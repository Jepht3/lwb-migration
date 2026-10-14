/**
 * Ouvre le lien de l'installeur comme le fait l'utilisateur : clic dans la page du site
 * source (autre site), dans un vrai navigateur. Les règles SameSite des cookies ne sont
 * appliquées que par les navigateurs : un client HTTP ne voit pas ce type de problème.
 *
 * Usage : node cross-site-link.js <page du site source> <lien de l'installeur>
 * Affiche le titre <h1> de la page obtenue. Code de sortie 2 si Playwright est absent.
 */
let chromium;
for (const path of ['playwright', '/opt/node22/lib/node_modules/playwright', '/opt/node-tools/node_modules/playwright']) {
	try {
		({ chromium } = require(path));
		break;
	} catch (e) {}
}
if (!chromium) {
	process.exit(2);
}

(async () => {
	const [from, target] = process.argv.slice(2);
	const browser = await chromium.launch();
	try {
		const page = await browser.newPage();
		await page.goto(from);
		await page.evaluate((href) => {
			const a = document.createElement('a');
			a.id = 'lwb-e2e-link';
			a.href = href;
			a.textContent = 'Ouvrir l’installeur';
			document.body.prepend(a);
		}, target);
		await Promise.all([page.waitForNavigation({ waitUntil: 'load' }), page.click('#lwb-e2e-link')]);
		const h1 = await page.locator('h1').first().textContent({ timeout: 10000 });
		console.log((h1 || '').trim());
	} finally {
		await browser.close();
	}
})().catch((e) => {
	console.error(e.message);
	process.exit(1);
});
