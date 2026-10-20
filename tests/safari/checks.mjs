// Safari (iOS simulator) checks for Jetonomy 2.0.4. Run: node checks.mjs
// Needs: Appium on :4723 with the xcuitest driver, a booted-or-bootable iPhone simulator,
// and a Local site with WP-CLI. Fixtures are created and removed here (QA-RFT prefix).
import { remote } from 'webdriverio';
import { execSync } from 'node:child_process';
import { writeFileSync } from 'node:fs';

const SITE = process.env.SITE_PATH || '/Users/vapvarun/Local Sites/forums/app/public';
const BASE = process.env.SITE_URL || 'http://forums.local';
const USER = process.env.QA_USER || 'maya';
const DEVICE = process.env.SIM_DEVICE || 'iPhone 16 Pro';
const IOS = process.env.SIM_IOS || '18.5';
const wp = (php) => execSync(`wp eval '${php}'`, { cwd: SITE, encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] }).trim().split('\n').pop();

const startedAt = new Date().toISOString().slice(0, 19).replace('T', ' ');
const results = [];
const check = (name, pass, detail = '') => { results.push({ name, pass, detail }); console.log(`${pass ? 'PASS' : 'FAIL'}  ${name}${detail ? '  - ' + detail : ''}`); };

// Fixtures: a topic with two pasted images, followed by the QA user.
const fx = JSON.parse(wp(`
$sp=$GLOBALS["wpdb"]->get_row("SELECT id,slug FROM {$GLOBALS["wpdb"]->prefix}jt_spaces WHERE slug=\\"london-chapter\\"");
$u=home_url("/wp-includes/images/wpicons-2x.png");
$id=\\Jetonomy\\Models\\Post::create(array("space_id"=>(int)$sp->id,"author_id"=>3762,"title"=>"QA-RFT safari checks","content"=>"<p>images</p><img src=\\"$u\\" alt=\\"a\\"><br><img src=\\"$u\\" alt=\\"b\\"><br>","status"=>"publish"));
\\Jetonomy\\Models\\Subscription::subscribe((int)get_user_by("login","${USER}")->ID,"post",(int)$id,"both");
echo wp_json_encode(array("id"=>(int)$id,"url"=>home_url("/community/s/{$sp->slug}/t/qa-rft-safari-checks/"),"space"=>home_url("/community/s/{$sp->slug}/new/")));`));

const driver = await remote({
  hostname: '127.0.0.1', port: 4723, logLevel: 'error',
  capabilities: {
    platformName: 'iOS', 'appium:automationName': 'XCUITest', 'appium:deviceName': DEVICE,
    'appium:platformVersion': IOS, browserName: 'Safari', 'appium:newCommandTimeout': 300,
    'appium:wdaLaunchTimeout': 300000, 'appium:connectHardwareKeyboard': false,
  },
});
const first = async (sel) => (await driver.$$(sel))[0];
// A real finger tap. element.click() is a synthetic click: iOS ignores it for file pickers and popups.
const tap = async (el) => {
  await driver.execute((e) => e.scrollIntoView({ block: 'center' }), el);
  await driver.pause(500);
  await driver.action('pointer', { parameters: { pointerType: 'touch' } }).move({ origin: el }).down().pause(80).up().perform();
};
const shot = (n) => driver.saveScreenshot(`out/${n}.png`);

try {
  await driver.url(`${BASE}/community/?autologin=${USER}`);

  // 1. Pasted-image click opens the full file in a new tab.
  await driver.url(fx.url);
  await driver.waitUntil(async () => (await driver.$$('.jt-post-body img')).length > 0, { timeout: 15000 });
  const img = await first('.jt-post-body img');
  const cursor = await driver.execute(() => getComputedStyle(document.querySelector('.jt-post-body img')).cursor);
  check('pasted image has zoom-in cursor', cursor === 'zoom-in', cursor);
  const handlesBefore = await driver.getWindowHandles();
  await tap(img);
  await driver.pause(2000);
  const handlesAfter = await driver.getWindowHandles();
  const newHandle = handlesAfter.find((h) => !handlesBefore.includes(h));
  let landed = '';
  if (newHandle) { await driver.switchToWindow(newHandle); landed = await driver.getUrl(); }
  else { landed = await driver.getUrl(); }
  await shot('1-after-image-tap');
  check('tap on pasted image shows the full-size file', /wpicons-2x\.png$/.test(landed), `${handlesBefore.length} -> ${handlesAfter.length} tabs, now at ${landed}`);
  if (newHandle) { await driver.closeWindow(); await driver.switchToWindow(handlesBefore[0]); }
  else if (/\.png$/.test(landed)) { await driver.back(); }

  // 2. My Subscriptions: Unfollow + confirm removes the row and it stays gone.
  await driver.url(`${BASE}/community/subscriptions/`);
  const rows0 = (await driver.$$('.jt-subs-row')).length;
  const btn = await driver.$(`//li[contains(@class,"jt-subs-row")][.//*[contains(text(),"QA-RFT safari checks")]]//button`);
  await btn.waitForExist({ timeout: 10000 });
  await tap(btn);
  await driver.pause(800);
  await shot('2-unfollow-confirm');
  const confirm = await first('.jt-modal-box .jt-modal-actions button:last-of-type');
  check('Unfollow opens a confirm dialog', !!confirm);
  if (confirm) await tap(confirm);
  await driver.pause(1500);
  const rows1 = (await driver.$$('.jt-subs-row')).length;
  check('row removed after confirm', rows1 === rows0 - 1, `${rows0} -> ${rows1}`);
  await driver.refresh();
  const gone = (await driver.$$('//*[contains(text(),"QA-RFT safari checks")]')).length;
  check('row stays gone after reload', gone === 0);

  // 3. Composer upload through the iOS photo picker.
  await driver.url(fx.space);
  await driver.waitUntil(async () => (await driver.$$('button[aria-label="Upload image"], button[title="Upload image"]')).length > 0, { timeout: 15000 });
  const upload = await first('button[aria-label="Upload image"], button[title="Upload image"]');
  await tap(upload);
  await driver.pause(2500);
  await driver.switchContext('NATIVE_APP');
  await shot('3-picker');
  const photoLib = await driver.$('~Photo Library');
  check('iOS picker offers Photo Library', await photoLib.isExisting());
  if (await photoLib.isExisting()) {
    await photoLib.click();
    await driver.pause(2500);
    await shot('3-photo-grid');
    // The photo grid is an out-of-process view Appium cannot read, so tap the first photo by position.
    const { width, height } = await driver.getWindowSize();
    await driver.execute('mobile: tap', { x: Math.round(width * 0.17), y: Math.round(height * 0.47) });
    await driver.pause(1500);
    await shot('3-photo-chosen');
    // Single choice still ends on a preview sheet; Done sits top right.
    await driver.execute('mobile: tap', { x: Math.round(width * 0.91), y: Math.round(height * 0.115) });
    await driver.pause(1500);
  }
  await driver.switchContext((await driver.getContexts()).find((c) => c.startsWith('WEBVIEW')));
  await driver.waitUntil(async () => (await driver.execute(() => document.querySelectorAll('[contenteditable] img').length)) > 0, { timeout: 30000 }).catch(() => {});
  const uploaded = await driver.execute(() => [...document.querySelectorAll('[contenteditable] img')].map((i) => i.src));
  check('Safari upload inserts the image into the editor', uploaded.length === 1, JSON.stringify(uploaded));
  check('uploaded file is in the random folder', uploaded.length === 1 && /\/uploads\/jetonomy\/[0-9a-f]{16}\//.test(uploaded[0]));
  await shot('3-after-upload');
} catch (e) {
  check('script error', false, String(e).slice(0, 300));
} finally {
  await driver.deleteSession().catch(() => {});
  // Remove the topic and any community upload this run created.
  wp(`\\Jetonomy\\Models\\Post::delete(${fx.id}); global $wpdb; foreach ($wpdb->get_col($wpdb->prepare("SELECT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id=p.ID AND m.meta_key=\\"_wp_attached_file\\" WHERE p.post_type=\\"attachment\\" AND m.meta_value LIKE \\"jetonomy/%\\" AND p.post_date_gmt >= %s", "${startedAt}")) as $a) { wp_delete_attachment((int)$a, true); } echo "cleaned";`);
}
console.log(`\n${results.filter(r => r.pass).length}/${results.length} passed`);
process.exit(results.every(r => r.pass) ? 0 : 1);
