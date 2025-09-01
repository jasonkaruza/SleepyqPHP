<?php

/**
 * tester.php
 * Command line test script for SleepyqPHP
 * 
 * Usage: php tester.php <username> <password>
 * Example: php tester.php user@example.com mypassword
 */

// Include the main SleepyqPHP library
require_once 'sleepyq.php';

// Function to print messages with timestamp
function logMessage($message)
{
    echo "[" . date('Y-m-d H:i:s') . "] " . $message . "\n";
}

// Check if we have the correct number of command line arguments
if ($argc != 3) {
    echo "Usage: php tester.php <username> <password>\n";
    echo "Example: php tester.php user@example.com mypassword\n";
    echo "Example: php tester.php \$SN_USER \$SN_OTHER\n";
    exit(1);
}

// Get username and password from command line arguments
$username = $argv[1];
$password = $argv[2];

logMessage("Starting SleepyqPHP test script");
logMessage("Username: " . $username);
logMessage("Password: " . str_repeat('*', strlen($password))); // Hide password in logs

try {
    // Create SleepyqPHP object
    logMessage("Creating SleepyqPHP object...");
    $sleepyq = new SleepyqPHP($username, $password);
    logMessage("SleepyqPHP object created successfully");

    // Attempt to login
    logMessage("Attempting to login...");
    $loginResult = $sleepyq->login();

    if ($loginResult) {
        logMessage("Login successful!");
    } else {
        logMessage("Login failed!");
        exit(1);
    }

    // Get beds information
    logMessage("Retrieving beds information...");
    $beds = $sleepyq->beds();

    if (empty($beds)) {
        logMessage("No beds found for this account");
    } else {
        logMessage("Found " . count($beds) . " bed(s):");

        foreach ($beds as $index => $bed) {
            logMessage("Bed #" . ($index + 1) . ":");
            logMessage("  - Bed ID: " . ($bed->bedId ?? 'N/A'));
            logMessage("  - Name: " . ($bed->name ?? 'N/A'));
            logMessage("  - Model: " . ($bed->model ?? 'N/A'));
            logMessage("  - Size: " . ($bed->size ?? 'N/A'));
            logMessage("  - Generation: " . ($bed->generation ?? 'N/A'));
            logMessage("  - MAC Address: " . ($bed->macAddress ?? 'N/A'));
            logMessage("  - Dual Sleep: " . (($bed->dualSleep ?? false) ? 'Yes' : 'No'));
            logMessage("  - Left Sleeper ID: " . ($bed->sleeperLeftId ?? 'N/A'));
            logMessage("  - Right Sleeper ID: " . ($bed->sleeperRightId ?? 'N/A'));
            logMessage("  - Status: " . ($bed->status ?? 'N/A'));
            logMessage("  - Timezone: " . ($bed->timezone ?? 'N/A'));

            if ($index < count($beds) - 1) {
                logMessage(""); // Add empty line between beds
            }
        }
    }

    // Test with foundation features
    logMessage("");
    logMessage("Retrieving beds with foundation features...");
    $bedsWithFeatures = $sleepyq->beds(true);

    logMessage("Retrieved " . count($bedsWithFeatures) . " bed(s) with foundation features");
    foreach ($bedsWithFeatures as $index => $bed) {
        if (isset($bed->foundationFeatures)) {
            logMessage("Bed #" . ($index + 1) . " foundation features available: Yes");
        } else {
            logMessage("Bed #" . ($index + 1) . " foundation features available: No");
        }
    }

    // Set bed ID for testing (use first bed)
    $bedId = '';
    if (!empty($beds)) {
        $bedId = $beds[0]->bedId;
        logMessage("");
        logMessage("=== LIGHTING TESTS ===");
        logMessage("Using Bed ID: " . $bedId);

        // Test 1: getFoundationFeatures() with specific focus on underbed light PWM
        logMessage("");
        logMessage("1. Testing getFoundationFeatures()...");
        $foundationFeatures = $sleepyq->getFoundationFeatures($bedId);
        if ($foundationFeatures) {
            logMessage("Foundation features retrieved successfully");
            logMessage("  - leftUnderbedLightPMW: " . ($foundationFeatures->leftUnderbedLightPMW ?? 'N/A'));
            logMessage("  - rightUnderbedLightPMW: " . ($foundationFeatures->rightUnderbedLightPMW ?? 'N/A'));
            logMessage("  - hasUnderbedLight: " . ($foundationFeatures->hasUnderbedLight ?? 'N/A'));
        } else {
            logMessage("Failed to retrieve foundation features");
        }

        // Sleep number favorite read
        $faves = $sleepyq->getFavSleepnumber($bedId);
        logMessage("Favorite Sleep Numbers: L=" . ($faves->left ?? 'n/a') . " R=" . ($faves->right ?? 'n/a'));
        // Set a temporary favorite (no-op if same)
        $targetFav = (($faves->left ?? 40) + 5) % 100;
        logMessage("Setting left favorite to $targetFav (temporary)");
        $sleepyq->setFavSleepnumber('left', $targetFav, $bedId);
        // Foot warming presence
        $fw = $sleepyq->getFoundationFootwarming($bedId);
        if ($fw) {
            $leftFW = $fw->footWarmingStatusLeft ?? 'n/a';
            $rightFW = $fw->footWarmingStatusRight ?? 'n/a';
            logMessage("Footwarming temps: L=$leftFW R=$rightFW");
        }
        // Underbed light state
        try {
            $lightStatus = $sleepyq->getLight(SleepyqPHP::RIGHT_NIGHT_LIGHT, $bedId);
            logMessage("Underbed light setting=" . ($lightStatus->setting ?? 'n/a') . " timer=" . ($lightStatus->timer ?? 'n/a'));
            $status = $sleepyq->isUnderBedLightingAutoModeEnabled($bedId);
            logMessage("Underbed light auto mode status: " . ($status ? 'Enabled' : 'Disabled'));
        } catch (Exception $e) {
            logMessage("Underbed light retrieval failed: " . $e->getMessage());
        }
        // Presets
        $presets = $sleepyq->getBedSidePresets($bedId);
        foreach ($presets as $side => $info) {
            logMessage("Preset for $side: " . json_encode($info));
        }

        // Test 2: Enable underbed lighting and check auto mode
        // logMessage("");
        // logMessage("2. Testing enableOrDisableUnderBedLighting(true)...");
        // $enableResult = $sleepyq->enableOrDisableUnderBedLighting(true, $bedId);
        // logMessage("Enable underbed lighting result: " . json_encode($enableResult, JSON_PRETTY_PRINT));

        // // Check if auto mode is enabled
        // $autoModeEnabled = $sleepyq->isUnderBedLightingAutoModeEnabled($bedId);
        // logMessage("Auto mode enabled after enabling: " . json_encode($autoModeEnabled, JSON_PRETTY_PRINT));

        // Test 3: Set light setting and timer, then verify with getLight()
        // logMessage("");
        // logMessage("3. Testing setLightSettingAndTimer() with timer...");
        // $timerValue = SleepyqPHP::LIGHT_TIMER_30; // 30 minutes
        // $setLightResult = $sleepyq->setLightSettingAndTimer(SleepyqPHP::LIGHT_SETTINGS_ON, SleepyqPHP::RIGHT_NIGHT_LIGHT, $timerValue, $bedId);
        // logMessage("Set light setting (ON) with timer ($timerValue min) result: " . json_encode($setLightResult));

        // Get light status to verify changes
        // $lightStatus = $sleepyq->getLight(SleepyqPHP::RIGHT_NIGHT_LIGHT, $bedId);
        // if ($lightStatus) {
        //     logMessage("Light status after setting:");
        //     logMessage("  - Setting: " . ($lightStatus->setting ?? 'N/A'));
        //     logMessage("  - Timer: " . ($lightStatus->timer ?? 'N/A'));
        //     logMessage("  - Outlet: " . ($lightStatus->outlet ?? 'N/A'));
        // } else {
        //     logMessage("Failed to retrieve light status");
        // }

        // // Check auto mode again
        // $autoModeAfterSet = $sleepyq->isUnderBedLightingAutoModeEnabled($bedId);
        // logMessage("Auto mode enabled after setting light: " . json_encode($autoModeAfterSet));

        // // Test 4: Set brightness and check foundation features
        // logMessage("");
        // $brightness = SleepyqPHP::LIGHT_BRIGHTNESS_HIGH;
        // logMessage("4. Testing setLightBrightness($brightness)...");
        // $brightnessResult = $sleepyq->setLightBrightness($brightness, $bedId);
        // logMessage("Set brightness ($brightness) result: " . json_encode($brightnessResult));

        // // Get foundation features again to check PWM values
        // logMessage("Checking foundation features after brightness change...");
        // $foundationFeaturesAfterBrightness = $sleepyq->getFoundationFeatures($bedId);
        // if ($foundationFeaturesAfterBrightness) {
        //     logMessage("Foundation features after brightness change:");
        //     logMessage("  - leftUnderbedLightPMW: " . ($foundationFeaturesAfterBrightness->leftUnderbedLightPMW ?? 'N/A'));
        //     logMessage("  - rightUnderbedLightPMW: " . ($foundationFeaturesAfterBrightness->rightUnderbedLightPMW ?? 'N/A'));

        //     // Compare with previous values if available
        //     if ($foundationFeatures) {
        //         $leftBefore = $foundationFeatures->leftUnderbedLightPMW ?? 'N/A';
        //         $rightBefore = $foundationFeatures->rightUnderbedLightPMW ?? 'N/A';
        //         $leftAfter = $foundationFeaturesAfterBrightness->leftUnderbedLightPMW ?? 'N/A';
        //         $rightAfter = $foundationFeaturesAfterBrightness->rightUnderbedLightPMW ?? 'N/A';

        //         logMessage("PWM Changes:");
        //         logMessage("  - Left PMW: $leftBefore -> $leftAfter");
        //         logMessage("  - Right PMW: $rightBefore -> $rightAfter");
        //     }
        // } else {
        //     logMessage("Failed to retrieve foundation features after brightness change");
        // }

        // // Test 5: Disable lighting and verify
        // logMessage("");
        // logMessage("5. Testing setLightSettingAndTimer() to disable...");
        // $disableLightResult = $sleepyq->setLightSettingAndTimer(SleepyqPHP::LIGHT_SETTINGS_OFF, SleepyqPHP::RIGHT_NIGHT_LIGHT, null, $bedId);
        // logMessage("Set light setting (OFF) result: " . json_encode($disableLightResult));

        // // Get light status to verify changes
        // $lightStatusAfterDisable = $sleepyq->getLight(SleepyqPHP::RIGHT_NIGHT_LIGHT, $bedId);
        // if ($lightStatusAfterDisable) {
        //     logMessage("Light status after disabling:");
        //     logMessage("  - Setting: " . ($lightStatusAfterDisable->setting ?? 'N/A'));
        //     logMessage("  - Timer: " . ($lightStatusAfterDisable->timer ?? 'N/A'));
        //     logMessage("  - Outlet: " . ($lightStatusAfterDisable->outlet ?? 'N/A'));
        // } else {
        //     logMessage("Failed to retrieve light status after disabling");
        // }

        // // Check auto mode final state
        // $autoModeFinal = $sleepyq->isUnderBedLightingAutoModeEnabled($bedId);
        // logMessage("Auto mode enabled after disabling light: " . json_encode($autoModeFinal));

        logMessage("");
        logMessage("=== LIGHTING TESTS COMPLETED ===");

        // Optional Fuzion tests (guarded by env var)
        if (getenv('SLEEPYQ_TEST_FUZION') === '1') {
            logMessage("");
            logMessage("=== FUZION (bamkey) FEATURE TESTS ===");
            // Detect if this is a Fuzion bed
            $generation = $beds[0]->generation ?? '';
            logMessage("Bed generation: $generation");
            if (strtolower($generation) === 'fuzion') {
                // Sleep number favorite read
                $faves = $sleepyq->getFavSleepnumber($bedId);
                logMessage("Favorite Sleep Numbers: L=" . ($faves->left ?? 'n/a') . " R=" . ($faves->right ?? 'n/a'));
                // Set a temporary favorite (no-op if same)
                $targetFav = (($faves->left ?? 40) + 5) % 100;
                logMessage("Setting left favorite to $targetFav (temporary)");
                $sleepyq->setFavSleepnumber('left', $targetFav, $bedId);
                // Foot warming presence
                $fw = $sleepyq->getFoundationFootwarming($bedId);
                if ($fw) {
                    $leftFW = $fw->footWarmingStatusLeft ?? 'n/a';
                    $rightFW = $fw->footWarmingStatusRight ?? 'n/a';
                    logMessage("Footwarming temps: L=$leftFW R=$rightFW");
                }
                // Underbed light state
                try {
                    $lightStatus = $sleepyq->getLight(SleepyqPHP::RIGHT_NIGHT_LIGHT, $bedId);
                    logMessage("Underbed light setting=" . ($lightStatus->setting ?? 'n/a') . " timer=" . ($lightStatus->timer ?? 'n/a'));
                    $status = $sleepyq->isUnderBedLightingAutoModeEnabled($bedId);
                } catch (Exception $e) {
                    logMessage("Underbed light retrieval failed: " . $e->getMessage());
                }
                // Presets
                $presets = $sleepyq->getBedSidePresets($bedId);
                foreach ($presets as $side => $info) {
                    logMessage("Preset for $side: " . json_encode($info));
                }
            } else {
                logMessage("Bed is not Fuzion; skipping Fuzion tests");
            }
            logMessage("=== FUZION (bamkey) FEATURE TESTS COMPLETED ===");
        }
    } else {
        logMessage("");
        logMessage("No beds available for lighting tests");
    }

    logMessage("");
    logMessage("Test completed successfully!");
} catch (Exception $e) {
    logMessage("ERROR: " . $e->getMessage());
    logMessage("Test failed!");
    exit(1);
}
