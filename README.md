# SleepyqPHP
PHP SDK for SleepNumber API, adapted from technicalpickles sleepyq (thank you :pickle:!):
 
 https://github.com/technicalpickles/sleepyq/

 Added support for footwarming based on:
 * https://github.com/tuctboh/adjustTheBed/blob/master/adjustTheBed-main.php
 * https://raw.githubusercontent.com/rvrolyk/SleepNumberController/master/SleepNumberController_App.groovy
 * https://community.hubitat.com/t/release-sleep-number-controller-control-your-sleep-number-bed-and-use-it-for-presence/46454/27?page=2
 * https://github.com/natecj/sleepiq-php/blob/master/SleepIQ.php
 * https://github.com/danpenn/SleepIQ/blob/1531466e2b64/control.go
   * Lights https://community.hubitat.com/t/release-sleep-number-controller-control-your-sleep-number-bed-and-use-it-for-presence/46454/501?page=7

## Notes and Learnings
- Setting underbed lights requires:
  - Setting Auto separate from On/Off for the setting
  - Setting brightness as part of the foundation for fields fsRightUNderbedLightPWM (left doesn't change for me)
  - Setting On/Off with Timer separate from the above