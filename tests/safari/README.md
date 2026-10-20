# Safari checks (iOS Simulator + Appium)

Drives real Mobile Safari on an iPhone simulator. macOS with Xcode only.

## One-time setup
    cd tests/safari
    npm install
    npm run driver        # installs the XCUITest driver into ./.appium

## Run
    npm run appium        # terminal 1, leave running
    npm test              # terminal 2

Needs a Local site with WP-CLI and the `maya` demo user. Override with
`SITE_PATH`, `SITE_URL`, `QA_USER`, `SIM_DEVICE`, `SIM_IOS`.

## What it checks
1. Pasted topic image shows the zoom cursor and a tap opens the full-size file in a new tab.
2. My Subscriptions: Unfollow asks to confirm, removes the row, and it stays gone after reload.
3. Composer: Upload image opens the iOS picker, a chosen photo lands in the editor under `/uploads/jetonomy/<random>/`.

Fixtures (topic `QA-RFT safari checks`) and the uploaded file are removed at the end. Screenshots go to `out/`.

## Gotchas
- Use finger taps (`tap()` in the script). `element.click()` is synthetic: iOS ignores it for the file picker and popups.
- The photo grid is an out-of-process view Appium cannot read, so the photo and Done button are tapped by screen position (first stock photo, top-right Done). Run on an erased simulator: `xcrun simctl erase <udid>`.
- Appium cannot list a new tab opened by `target="_blank"` from a synthetic click; a real tap does (handles go 1 -> 2).
