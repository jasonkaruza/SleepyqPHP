<?php

/**
 * compare_generations.php
 *
 * Usage:
 *   php compare_generations.php <non_fuzion_user> <non_fuzion_pass> <fuzion_user> <fuzion_pass>
 *
 * Purpose:
 *   Logs into two separate SleepIQ accounts: one that has a legacy (non-Fuzion) generation bed
 *   and one that has a Fuzion generation bed. It then invokes a set of read-only API methods
 *   that have Fuzion branching logic in sleepyq.php and compares the top-level property keys
 *   of the returned objects. The Fuzion result must contain at least all non-Fuzion properties,
 *   and any non-empty (non-null, non-empty-string, non-empty-array) value from the non-Fuzion
 *   result may not be missing or empty on the Fuzion result.
 *
 *   Discrepancies are flagged for investigation.
 *
 * Methods Compared (read-only variants with Fuzion logic):
 *   - getFoundationFootwarming
 *   - getLight
 *   - getFavSleepnumber
 *   - getFoundationSystem
 *   - getFoundationFeatures
 *   - getBedSidePresets
 *
 * Notes:
 *   - Side-effecting setters (setSleepnumber, preset, etc.) are intentionally excluded.
 *   - If either side returns null (e.g. no foundation present), that comparison is skipped.
 *   - Only top-level properties of the returned object are compared (including 'data').
 *   - This script exits with a non-zero status if any discrepancies are found.
 *   - Recursive comparison: ensures all nested keys present (objects/arrays) in Fuzion response
 *     when present and non-empty in legacy response.
 */

require_once 'sleepyq.php';

if ($argc !== 5) {
    fwrite(STDERR, "Usage: php compare_generations.php <non_fuzion_user> <non_fuzion_pass> <fuzion_user> <fuzion_pass>\n");
    exit(2);
}

list(, $legacyUser, $legacyPass, $fuzionUser, $fuzionPass) = $argv;

function logMsg($msg)
{
    echo '[' . date('H:i:s') . "] $msg\n";
}

function isEmptyValue($v)
{
    if ($v === null) return true;
    if (is_string($v) && $v === '') return true;
    if (is_array($v) && count($v) === 0) return true;
    if (is_object($v)) {
        $props = get_object_vars($v);
        return count($props) === 0; // Empty object
    }
    return false; // numeric 0, false treated as meaningful
}

function normalizeValue($v)
{
    // Convert APIObject descendants to plain array structure recursively for comparison
    if (is_object($v)) {
        $out = [];
        foreach (get_object_vars($v) as $k => $vv) {
            $out[$k] = normalizeValue($vv);
        }
        return $out;
    }
    if (is_array($v)) {
        $out = [];
        foreach ($v as $k => $vv) {
            $out[$k] = normalizeValue($vv);
        }
        return $out;
    }
    return $v;
}

function compareRecursive($legacy, $fuzion, $path = 'root', &$issues = [])
{
    $legacyNorm = normalizeValue($legacy);
    $fuzionNorm = normalizeValue($fuzion);
    if (is_array($legacyNorm)) {
        foreach ($legacyNorm as $k => $legacyVal) {
            $childPath = $path . '.' . $k;
            if (!array_key_exists($k, (array)$fuzionNorm)) {
                $issue = [
                    'type' => 'missing',
                    'path' => $childPath,
                    'legacySample' => summarizeValue($legacyVal),
                    'fuzionSample' => null,
                    'legacyRawType' => gettype($legacyVal),
                    'fuzionRawType' => 'NULL',
                ];
                if (!isBenignIssue($issue)) {
                    $issues[] = $issue;
                }
                continue;
            }
            $fuzVal = $fuzionNorm[$k];
            if (!isEmptyValue($legacyVal) && isEmptyValue($fuzVal)) {
                $issue = [
                    'type' => 'empty-mismatch',
                    'path' => $childPath,
                    'legacySample' => summarizeValue($legacyVal),
                    'fuzionSample' => summarizeValue($fuzVal),
                    'legacyRawType' => gettype($legacyVal),
                    'fuzionRawType' => gettype($fuzVal),
                ];
                if (!isBenignIssue($issue)) {
                    $issues[] = $issue;
                }
            }
            if ((is_array($legacyVal) || is_object($legacyVal))) {
                compareRecursive($legacyVal, $fuzVal, $childPath, $issues);
            }
        }
    } else {
        if (!isEmptyValue($legacyNorm) && isEmptyValue($fuzionNorm)) {
            $issue = [
                'type' => 'empty-mismatch',
                'path' => $path,
                'legacySample' => summarizeValue($legacyNorm),
                'fuzionSample' => summarizeValue($fuzionNorm),
                'legacyRawType' => gettype($legacyNorm),
                'fuzionRawType' => gettype($fuzionNorm),
            ];
            if (!isBenignIssue($issue)) {
                $issues[] = $issue;
            }
        }
    }
}

// Patterns for benign differences (missing or empty-mismatch) we want to ignore
$BENIGN_PATTERNS = [
    '/\\.(lastUpdated|updatedAt|timestamp|positionTimestamp|statusUpdated)$/i',
    '/\\.(motionInProgress|pumpInProgress|isMoving|isPumping|adjusting)$/i',
    '/\\.(remaining(Time)?|timeRemaining|footWarmingRemaining|heaterRemaining|sessionMinutes)$/i',
    '/\\.(lightTimer|light(Time)?Remaining|autoLight|ambientMode)$/i',
    '/\\.(firmwareVersion|foundationFirmwareVersion|swVersion|buildVersion|hardwareRevision)$/i',
    '/\\.(fsBoard(Faults|Features|HWRevisionCode|Status)|fsBedType)$/i',
    '/\\.(capabilities|feature(Map|Flags)?|fuzionFeature.*|supported|available)$/i',
    '/\\.(accountId|bedId|deviceId|assetId|moduleId)$/i',
    '/\\.meta(\\.|$)/i',
    '/\\.diagnostics(\\.|$)/i',
    '/\\.telemetry(\\.|$)/i',
    '/\\.analytics(\\.|$)/i',
    '/\\.(brightness|intensity|level(Name|Value))$/i',
];

function isBenignIssue(array $issue): bool
{
    global $BENIGN_PATTERNS;
    $path = $issue['path'] ?? '';
    foreach ($BENIGN_PATTERNS as $re) {
        if (preg_match($re, $path)) {
            // Special case: numeric vs null considered benign for motion/pump style fields
            if (($issue['legacyRawType'] === 'integer' || $issue['legacyRawType'] === 'double') && $issue['fuzionRawType'] === 'NULL') {
                return true;
            }
            return true;
        }
    }
    return false;
}

function summarizeValue($v)
{
    $json = json_encode(normalizeValue($v), JSON_UNESCAPED_SLASHES);
    if ($json === false) return '<<unencodable>>';
    if (strlen($json) > 300) {
        return substr($json, 0, 297) . '...';
    }
    return $json;
}

$legacy = new SleepyqPHP($legacyUser, $legacyPass);
$fuzion = new SleepyqPHP($fuzionUser, $fuzionPass);

logMsg('Logging in (legacy)...');
$legacy->login();
logMsg('Logging in (fuzion)...');
$fuzion->login();

$legacyBeds = $legacy->beds();
$fuzionBeds = $fuzion->beds();
if (!count($legacyBeds)) {
    fwrite(STDERR, "No beds found in legacy account\n");
    exit(3);
}
if (!count($fuzionBeds)) {
    fwrite(STDERR, "No beds found in fuzion account\n");
    exit(3);
}

$legacyBed = $legacyBeds[0];
$fuzionBed = $fuzionBeds[0];

logMsg('Legacy bedId=' . $legacyBed->bedId . ' generation=' . ($legacyBed->generation ?? ''));
logMsg('Fuzion bedId=' . $fuzionBed->bedId . ' generation=' . ($fuzionBed->generation ?? ''));

if (strtolower($legacyBed->generation ?? '') === 'fuzion') {
    fwrite(STDERR, "ERROR: First legacy account bed is actually fuzion. Provide correct credentials.\n");
    exit(4);
}
if (strtolower($fuzionBed->generation ?? '') !== 'fuzion') {
    fwrite(STDERR, "ERROR: First fuzion account bed is not fuzion. Provide correct credentials.\n");
    exit(4);
}

$bedIdLegacy = $legacyBed->bedId;
$bedIdFuzion = $fuzionBed->bedId;

// Explicit method invocation (no dynamic call indirection) with proper argument lists
$failures = [];

function compareMethod($label, $legacyResult, $fuzionResult, &$failures)
{
    logMsg("Comparing method: $label");
    if ($legacyResult === null || $fuzionResult === null) {
        logMsg("  Skipping comparison (one result null)");
        return;
    }
    $issues = [];
    compareRecursive($legacyResult, $fuzionResult, $label, $issues);
    if (!$issues) {
        logMsg("  PASS: Recursive structure parity confirmed");
    } else {
        logMsg("  FAIL: " . count($issues) . " discrepancy(ies)");
        $failures[] = [
            'method' => $label,
            'count' => count($issues),
            'issues' => $issues,
            'legacySampleRoot' => summarizeValue($legacyResult),
            'fuzionSampleRoot' => summarizeValue($fuzionResult),
        ];
        foreach ($issues as $i) logMsg("    [" . $i['type'] . "] " . $i['path']);
    }
}

// getFoundationFootwarming
try {
    $legacyFoot = $legacy->getFoundationFootwarming($bedIdLegacy);
} catch (Exception $e) {
    logMsg("  Legacy getFoundationFootwarming failed: " . $e->getMessage());
    $legacyFoot = null;
}
try {
    $fuzionFoot = $fuzion->getFoundationFootwarming($bedIdFuzion);
} catch (Exception $e) {
    logMsg("  Fuzion getFoundationFootwarming failed: " . $e->getMessage());
    $fuzionFoot = null;
}
compareMethod('getFoundationFootwarming', $legacyFoot, $fuzionFoot, $failures);

// getLight (explicit default constant for clarity)
try {
    $legacyLight = $legacy->getLight(SleepyqPHP::RIGHT_NIGHT_LIGHT, $bedIdLegacy);
} catch (Exception $e) {
    logMsg("  Legacy getLight failed: " . $e->getMessage());
    $legacyLight = null;
}
try {
    $fuzionLight = $fuzion->getLight(SleepyqPHP::RIGHT_NIGHT_LIGHT, $bedIdFuzion);
} catch (Exception $e) {
    logMsg("  Fuzion getLight failed: " . $e->getMessage());
    $fuzionLight = null;
}
compareMethod('getLight', $legacyLight, $fuzionLight, $failures);

// getFavSleepnumber
try {
    $legacyFav = $legacy->getFavSleepnumber($bedIdLegacy);
} catch (Exception $e) {
    logMsg("  Legacy getFavSleepnumber failed: " . $e->getMessage());
    $legacyFav = null;
}
try {
    $fuzionFav = $fuzion->getFavSleepnumber($bedIdFuzion);
} catch (Exception $e) {
    logMsg("  Fuzion getFavSleepnumber failed: " . $e->getMessage());
    $fuzionFav = null;
}
compareMethod('getFavSleepnumber', $legacyFav, $fuzionFav, $failures);

// getFoundationSystem
try {
    $legacySys = $legacy->getFoundationSystem($bedIdLegacy);
} catch (Exception $e) {
    logMsg("  Legacy getFoundationSystem failed: " . $e->getMessage());
    $legacySys = null;
}
try {
    $fuzionSys = $fuzion->getFoundationSystem($bedIdFuzion);
} catch (Exception $e) {
    logMsg("  Fuzion getFoundationSystem failed: " . $e->getMessage());
    $fuzionSys = null;
}
compareMethod('getFoundationSystem', $legacySys, $fuzionSys, $failures);

// getFoundationFeatures
try {
    $legacyFeat = $legacy->getFoundationFeatures($bedIdLegacy);
} catch (Exception $e) {
    logMsg("  Legacy getFoundationFeatures failed: " . $e->getMessage());
    $legacyFeat = null;
}
try {
    $fuzionFeat = $fuzion->getFoundationFeatures($bedIdFuzion);
} catch (Exception $e) {
    logMsg("  Fuzion getFoundationFeatures failed: " . $e->getMessage());
    $fuzionFeat = null;
}
compareMethod('getFoundationFeatures', $legacyFeat, $fuzionFeat, $failures);

// getBedSidePresets
try {
    $legacyPresets = $legacy->getBedSidePresets($bedIdLegacy);
} catch (Exception $e) {
    logMsg("  Legacy getBedSidePresets failed: " . $e->getMessage());
    $legacyPresets = null;
}
try {
    $fuzionPresets = $fuzion->getBedSidePresets($bedIdFuzion);
} catch (Exception $e) {
    logMsg("  Fuzion getBedSidePresets failed: " . $e->getMessage());
    $fuzionPresets = null;
}
compareMethod('getBedSidePresets', $legacyPresets, $fuzionPresets, $failures);

logMsg(str_repeat('-', 60));
$readOnlyFailed = count($failures) > 0;
if ($readOnlyFailed) {
    logMsg('SUMMARY: FAIL (' . count($failures) . ' methods with discrepancies)');
    foreach ($failures as $f) {
        logMsg('  Method ' . $f['method'] . ' discrepancies=' . $f['count']);
        $detailJson = json_encode($f['issues'], JSON_UNESCAPED_SLASHES);
        if ($detailJson && strlen($detailJson) > 1200) {
            $detailJson = substr($detailJson, 0, 1197) . '...';
        }
        logMsg('    Issues: ' . $detailJson);
    }
} else {
    logMsg('SUMMARY: PASS (no discrepancies)');
}

// Optional setter round-trip tests (guarded)
$setterFailures = [];
logMsg(str_repeat('=', 60));
logMsg('BEGIN SETTER ROUND-TRIP TESTS (guarded by SLEEPYQ_TEST_SETTERS=1)');
$runSetter = function ($label, callable $legacySetter, callable $legacyGetter, callable $fuzionSetter, callable $fuzionGetter, callable $compareFn) use (&$setterFailures, $legacy, $fuzion) {
    logMsg("[Setter] $label");
    try {
        $legacyBefore = $legacyGetter();
    } catch (Exception $e) {
        $legacyBefore = null;
        logMsg("  Legacy before read failed: " . $e->getMessage());
    }
    try {
        $fuzionBefore = $fuzionGetter();
    } catch (Exception $e) {
        $fuzionBefore = null;
        logMsg("  Fuzion before read failed: " . $e->getMessage());
    }
    try {
        $legacySetter();
    } catch (Exception $e) {
        logMsg("  Legacy set failed: " . $e->getMessage());
    }
    try {
        $fuzionSetter();
    } catch (Exception $e) {
        logMsg("  Fuzion set failed: " . $e->getMessage());
    }
    try {
        $legacyAfter = $legacyGetter();
    } catch (Exception $e) {
        $legacyAfter = null;
    }
    try {
        $fuzionAfter = $fuzionGetter();
    } catch (Exception $e) {
        $fuzionAfter = null;
    }
    if ($legacyAfter && $fuzionAfter) {
        $issues = [];
        compareRecursive($legacyAfter, $fuzionAfter, $label, $issues);
        if ($issues) {
            $setterFailures[] = ['label' => $label, 'issues' => $issues];
            logMsg("  FAIL: Structural discrepancies post-set");
        } else {
            $compareFn($legacyBefore, $legacyAfter, $fuzionBefore, $fuzionAfter);
            logMsg("  PASS");
        }
    } else {
        $setterFailures[] = ['label' => $label, 'issues' => [['type' => 'read-failure', 'path' => $label]]];
        logMsg("  SKIP: Could not read both results post-set");
    }
};

$runSetter(
    'setSleepnumber(left)',
    function () use ($legacy, $bedIdLegacy) {
        $legacy->setSleepnumber('left', 45, $bedIdLegacy);
    },
    function () use ($legacy, $bedIdLegacy) {
        return $legacy->getBedFamilyStatus();
    },
    function () use ($fuzion, $bedIdFuzion) {
        $fuzion->setSleepnumber('left', 45, $bedIdFuzion);
    },
    function () use ($fuzion, $bedIdFuzion) {
        return $fuzion->getBedFamilyStatus();
    },
    function ($lBefore, $lAfter, $fBefore, $fAfter) { /* no-op */
    }
);
$runSetter(
    'setFavSleepnumber(left)',
    function () use ($legacy, $bedIdLegacy) {
        $legacy->setFavSleepnumber('left', 50, $bedIdLegacy);
    },
    function () use ($legacy, $bedIdLegacy) {
        return $legacy->getFavSleepnumber($bedIdLegacy);
    },
    function () use ($fuzion, $bedIdFuzion) {
        $fuzion->setFavSleepnumber('left', 50, $bedIdFuzion);
    },
    function () use ($fuzion, $bedIdFuzion) {
        return $fuzion->getFavSleepnumber($bedIdFuzion);
    },
    function ($lBefore, $lAfter, $fBefore, $fAfter) { /* no-op */
    }
);
$runSetter(
    'preset(favorite,left)',
    function () use ($legacy, $bedIdLegacy) {
        $legacy->preset(SleepyqPHP::FAVORITE, 'left', $bedIdLegacy);
    },
    function () use ($legacy, $bedIdLegacy) {
        return $legacy->getBedSidePresets($bedIdLegacy);
    },
    function () use ($fuzion, $bedIdFuzion) {
        $fuzion->preset(SleepyqPHP::FAVORITE, 'left', $bedIdFuzion);
    },
    function () use ($fuzion, $bedIdFuzion) {
        return $fuzion->getBedSidePresets($bedIdFuzion);
    },
    function ($lBefore, $lAfter, $fBefore, $fAfter) { /* no-op */
    }
);
$runSetter(
    'setLightBrightness(low)',
    function () use ($legacy, $bedIdLegacy) {
        $legacy->setLightBrightness(SleepyqPHP::LIGHT_BRIGHTNESS_LOW, $bedIdLegacy);
    },
    function () use ($legacy, $bedIdLegacy) {
        return $legacy->getFoundationSystem($bedIdLegacy);
    },
    function () use ($fuzion, $bedIdFuzion) {
        $fuzion->setLightBrightness(SleepyqPHP::LIGHT_BRIGHTNESS_LOW, $bedIdFuzion);
    },
    function () use ($fuzion, $bedIdFuzion) {
        return $fuzion->getFoundationSystem($bedIdFuzion);
    },
    function ($lBefore, $lAfter, $fBefore, $fAfter) { /* no-op */
    }
);

$runSetter(
    'setFoundationFootwarming(left,low)',
    function () use ($legacy, $bedIdLegacy) {
        $legacy->setFoundationFootwarming('left', SleepyqPHP::FOOTWARM_LOW, SleepyqPHP::FOOTWARM_30, $bedIdLegacy);
    },
    function () use ($legacy, $bedIdLegacy) {
        return $legacy->getFoundationFootwarming($bedIdLegacy);
    },
    function () use ($fuzion, $bedIdFuzion) {
        $fuzion->setFoundationFootwarming('left', SleepyqPHP::FOOTWARM_LOW, SleepyqPHP::FOOTWARM_30, $bedIdFuzion);
    },
    function () use ($fuzion, $bedIdFuzion) {
        return $fuzion->getFoundationFootwarming($bedIdFuzion);
    },
    function ($lBefore, $lAfter, $fBefore, $fAfter) { /* no-op */
    }
);

if ($setterFailures) {
    logMsg('SETTER SUMMARY: FAIL (' . count($setterFailures) . ' setters with discrepancies)');
    foreach ($setterFailures as $s) {
        logMsg('  Setter ' . $s['label'] . ' issues=' . count($s['issues']));
        $json = json_encode($s['issues'], JSON_UNESCAPED_SLASHES);
        if ($json && strlen($json) > 1200) $json = substr($json, 0, 1197) . '...';
        logMsg('    ' . $json);
    }
} else {
    logMsg('SETTER SUMMARY: PASS');
}

if ($readOnlyFailed) exit(1);
if ($setterFailures) exit(5);
exit(0);
