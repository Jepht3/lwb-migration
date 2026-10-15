/**
 * Audit de mise en page : chevauchements, débordements et lignes de texte superposées,
 * de 320 à 1280 px de large, dans tous les états de l'interface (progression, erreur,
 * résultat, clé…) remplis avec des textes longs et réalistes.
 *
 * Usage :
 *   node layout-audit.js admin <url du site> <identifiant> <mot de passe> [dossier captures]
 *   node layout-audit.js installer <url d'installer.php> <mot de passe> [dossier captures]
 * Affiche un problème par ligne puis « TOTAL n ». Code de sortie 2 si Playwright est absent.
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
const fs = require('fs');
const WIDTHS = [320, 360, 390, 412, 600, 782, 1024, 1280];
const KEY = 'LWB1.' + 'eyJ1IjoiaHR0cHM6Ly93d3cuZXhlbXBsZS1kZXN0aW5hdGlvbi5mci93cC1hZG1pbi9hZG1pbi1hamF4LnBocCIsImsiOiJjODY2M2FlNmE2YzU4NzBlIiwicyI6ImNhZmI2MmI3NzQyODcwN2EzOThlYjI0MjQ1ZTBkNWIyMjVlNTE0ZDMzZDczMmM3NjVhOWE4OGQ5NjM4OWMxMWYiLCJlIjoxNzkyMDc3NTAzLCJ2IjoxfQ' + '.vX41whtLvmUm2A5ymrW6R6nhdQ5bOgN439SrkRGRXJI';

// Met l'interface dans tous ses états, avec des textes longs et réalistes.
function fillStates(key) {
	const $ = (s, r = document) => r.querySelector(s);
	const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));
	$$('.lwbm [hidden]').forEach((e) => e.removeAttribute('hidden'));
	const n = $('#lwbm-notice');
	if (n) {
		n.innerHTML = '<strong>Le pare-feu de l’hébergeur du site de destination bloque le transfert (HTTP 403).</strong><p class="lwbm-solution">Solution : Autorisez l’adresse IP de ce serveur (203.0.113.250) dans la protection de l’hébergeur de destination (chez o2switch : cPanel › Outils o2switch › Sécurité), ou désactivez-la le temps de la migration, puis cliquez sur Reprendre. https://www.exemple-destination.fr/wp-admin/admin-ajax.php?action=lwb_migration_receive</p><button type="button" class="lwbm-btn lwbm-btn-primary">Reprendre</button>';
	}
	$$('[data-lwbm-progress]').forEach((p) => {
		p.classList.add('is-error');
		$$('[data-lwbm-phase]', p).forEach((li, i) => li.classList.add(i < 3 ? 'is-done' : i === 3 ? 'is-current' : 'x'));
		const l = $('[data-lwbm-label]', p); if (l) l.textContent = 'Transfert vers le site de destination';
		const d = $('[data-lwbm-detail]', p); if (d) d.textContent = '551,2 Mo / 839,8 Mo envoyés – archive-688bdfc98ce04b6cee1efb7b8b48be7f.zip';
		const pc = $('[data-lwbm-percent]', p); if (pc) pc.textContent = '65.6 %';
		const f = $('[data-lwbm-fill]', p); if (f) f.style.width = '65.6%';
		const e = $('[data-lwbm-elapsed]', p); if (e) e.textContent = 'Durée : 7 min 52 s';
		const r = $('[data-lwbm-retry]', p); if (r) r.textContent = 'Nouvel essai dans 12 s (tentative 2/5)…';
		const lg = $('[data-lwbm-log]', p);
		if (lg) lg.textContent = '[2026-10-14 16:38:03] [INFO] Paquet prêt : archive-688bdfc98ce04b6cee1efb7b8b48be7f.zip (839,8 Mo), installer.php généré.\n[2026-10-14 16:38:03] [INFO] Transfert vers https://www.exemple-destination.fr : 839,8 Mo en morceaux de 4,0 Mo.';
		const det = $('details', p); if (det) det.open = true;
	});
	const kt = $('#lwbm-key-box textarea, #lwbm-key-box .lwbm-code, #lwbm-key-box code');
	if (kt) { if ('value' in kt && kt.tagName === 'TEXTAREA') kt.value = key; else kt.textContent = key; }
	const dk = $('#lwbm-direct-key'); if (dk) dk.value = key;
	$$('details.lwbm-advanced').forEach((d) => (d.open = true));
	const via = $('#lwbm-direct-via'); if (via) via.value = 'https://serveur-mutualise-42.hebergeur-exemple.fr/~compte-client';
	$$('[data-lwbm-site]').forEach((s) => (s.textContent = 'https://www.exemple-destination-tres-longue.fr'));
	$$('[data-lwbm-hosts]').forEach((s) => (s.textContent = '203.0.113.10 www.exemple-destination-tres-longue.fr'));
	$$('[data-lwbm-password]').forEach((c) => (c.textContent = 'Xk7#pQ2v-Lm9$Rt4-Wz8!'));
	$$('[data-lwbm-update-state]').forEach((s) => (s.lastChild.textContent = ' Version 1.2.1 – à jour'));
	// Bandeau d'une autre extension (comme « Laisser un avis » chez l'utilisateur).
	const h = $('.wp-header-end');
	if (h && !$('#e2e-foreign')) {
		const div = document.createElement('div');
		div.id = 'e2e-foreign';
		div.className = 'notice notice-success is-dismissible';
		div.innerHTML = '<p>Vous utilisez notre extension depuis un moment, pourriez-vous laisser un avis ? <a class="button button-primary" href="#">Laisser un avis</a> <a href="#">Peut-être plus tard</a></p>';
		h.after(div);
	}
}

// Détection : renvoie une liste de problèmes.
function detect() {
	const issues = [];
	const root = document.querySelector('.lwbm') || document.body;
	const vw = document.documentElement.clientWidth;
	const name = (el) => {
		let s = el.tagName.toLowerCase();
		if (el.id) s += '#' + el.id;
		const cls = Array.from(el.classList).filter((c) => !/^is-/.test(c)).slice(0, 2);
		if (cls.length) s += '.' + cls.join('.');
		const t = (el.innerText || el.value || '').trim().replace(/\s+/g, ' ').slice(0, 40);
		return t ? `${s} « ${t} »` : s;
	};
	const visible = (el) => {
		if (el.closest('svg') && el.tagName.toLowerCase() !== 'svg') return false;
		if (el.closest('.screen-reader-text, #e2e-foreign')) return false;
		const cs = getComputedStyle(el);
		if (cs.display === 'none' || cs.visibility === 'hidden' || +cs.opacity === 0) return false;
		const r = el.getBoundingClientRect();
		return r.width > 0 && r.height > 0;
	};
	if (document.documentElement.scrollWidth > window.innerWidth + 1) {
		issues.push(`défilement horizontal de la page : ${document.documentElement.scrollWidth}px > ${window.innerWidth}px`);
	}
	const els = Array.from(root.querySelectorAll('*')).filter(visible);
	for (const el of els) {
		const r = el.getBoundingClientRect();
		const cs = getComputedStyle(el);
		if (cs.position === 'fixed') continue;
		// 1. Sort de l'écran.
		if (r.right > vw + 1 || r.left < -1) {
			// sauf à l'intérieur d'un conteneur qui défile (onglets, journal).
			let p = el.parentElement, scrolled = false;
			while (p && p !== document.body) { const o = getComputedStyle(p).overflowX; if (o === 'auto' || o === 'scroll' || o === 'hidden') { scrolled = true; break; } p = p.parentElement; }
			if (!scrolled) issues.push(`hors écran (${Math.round(r.left)}→${Math.round(r.right)} / ${vw}) : ${name(el)}`);
		}
		// 2. Déborde de son parent.
		const p = el.parentElement;
		if (p && p !== root.parentElement && cs.position !== 'absolute') {
			const pcs = getComputedStyle(p);
			if (pcs.overflowX === 'visible') {
				const pr = p.getBoundingClientRect();
				if (r.right > pr.right + 2 || r.left < pr.left - 2) issues.push(`déborde de son parent ${name(p).slice(0, 50)} de ${Math.round(Math.max(r.right - pr.right, pr.left - r.left))}px : ${name(el)}`);
			}
		}
		// 3. Texte coupé (overflow hidden avec contenu plus large).
		if ((cs.overflowX === 'hidden' || cs.overflow === 'hidden') && el.scrollWidth > el.clientWidth + 2 && cs.textOverflow !== 'ellipsis' && el.children.length === 0 && el.innerText.trim()) {
			issues.push(`texte coupé : ${name(el)}`);
		}
	}
	// 4. Frères qui se superposent (flux normal, flex, grid).
	const parents = new Set(els.map((e) => e.parentElement));
	for (const p of parents) {
		if (!p) continue;
		const kids = Array.from(p.children).filter((k) => visible(k) && !['absolute', 'fixed'].includes(getComputedStyle(k).position));
		for (let i = 0; i < kids.length; i++) for (let j = i + 1; j < kids.length; j++) {
			const a = kids[i].getBoundingClientRect(), b = kids[j].getBoundingClientRect();
			const w = Math.min(a.right, b.right) - Math.max(a.left, b.left);
			const h = Math.min(a.bottom, b.bottom) - Math.max(a.top, b.top);
			if (w > 2 && h > 2) issues.push(`superposition (${Math.round(w)}×${Math.round(h)}px) : ${name(kids[i])}  ⟷  ${name(kids[j])}`);
		}
	}
	// 5. Lignes d'un même texte qui se chevauchent (line-height trop petit, retour à la ligne).
	const walker = document.createTreeWalker(root, NodeFilter.SHOW_ELEMENT);
	for (let el = walker.currentNode; el; el = walker.nextNode()) {
		if (!visible(el) || !el.childNodes.length) continue;
		const rects = [];
		for (const tn of el.childNodes) {
			if (tn.nodeType !== 3 || !tn.textContent.trim()) continue;
			const range = document.createRange();
			range.selectNodeContents(tn);
			rects.push(...Array.from(range.getClientRects()).filter((x) => x.width > 1 && x.height > 1));
		}
		if (rects.length < 2) continue;
		const lines = [];
		for (const x of rects) { if (!lines.some((l) => Math.abs(l.top - x.top) < 2)) lines.push(x); }
		lines.sort((a, b) => a.top - b.top);
		for (let i = 1; i < lines.length; i++) {
			const ov = lines[i - 1].bottom - lines[i].top;
			if (ov > 4 && Math.min(lines[i - 1].right, lines[i].right) > Math.max(lines[i - 1].left, lines[i].left)) {
				issues.push(`lignes de texte superposées (${Math.round(ov)}px) : ${name(el)}`);
				break;
			}
		}
	}
	return Array.from(new Set(issues));
}

function fillInstaller() {
	document.querySelectorAll('[hidden]').forEach((e) => e.removeAttribute('hidden'));
	const er = document.getElementById('error');
	if (er) er.innerHTML = '<strong>Connexion à la base de données impossible : Access denied for user \'utilisateur_wp\'@\'localhost\' (using password: YES)</strong><p>Vérifiez l’utilisateur, le mot de passe et l’hôte MySQL (souvent localhost) dans cPanel › Bases de données MySQL.</p>';
}

(async () => {
	const [mode, url, a, b, c] = process.argv.slice(2);
	const shots = mode === 'admin' ? c : b;
	if (shots) fs.mkdirSync(shots, { recursive: true });
	const browser = await chromium.launch();
	const issues = [];
	const run = async (label, ctxOpts, prepare) => {
		for (const w of WIDTHS) {
			const ctx = await browser.newContext(Object.assign({ viewport: { width: w, height: 900 }, isMobile: w < 600, hasTouch: w < 600 }, ctxOpts));
			const p = await ctx.newPage();
			await prepare(p);
			const found = await p.evaluate(detect);
			found.forEach((x) => issues.push(`${label}@${w} : ${x}`));
			if (shots) await p.screenshot({ path: `${shots}/${label}-${w}.png`, fullPage: true });
			await ctx.close();
		}
	};
	if (mode === 'admin') {
		const ctx = await browser.newContext();
		const login = await ctx.newPage();
		await login.goto(url + '/wp-login.php');
		await login.fill('#user_login', a);
		await login.fill('#user_pass', b);
		await Promise.all([login.waitForNavigation(), login.click('#wp-submit')]);
		const state = await ctx.storageState();
		for (const tab of ['export', 'direct', 'import', 'settings']) {
			await run(tab, { storageState: state }, async (p) => {
				await p.goto(`${url}/wp-admin/admin.php?page=lwb-migration&tab=${tab}`);
				await p.waitForTimeout(600);
				await p.evaluate(fillStates, KEY);
				await p.waitForTimeout(200);
			});
		}
	} else {
		for (const scheme of ['light', 'dark']) {
			await run(`installeur-connexion-${scheme}`, { colorScheme: scheme }, async (p) => {
				await p.goto(url);
				await p.evaluate(() => { const e = document.getElementById('login-error'); if (e) { e.hidden = false; e.textContent = 'Mot de passe incorrect. Encore 4 essais avant un blocage de 15 minutes.'; } });
			});
			await run(`installeur-${scheme}`, { colorScheme: scheme }, async (p) => {
				await p.goto(url);
				await p.fill('input[type=password]', a);
				await Promise.all([p.waitForNavigation(), p.click('button[type=submit]')]);
				await p.waitForTimeout(1200);
				await p.evaluate(fillInstaller);
				await p.waitForTimeout(200);
			});
		}
	}
	issues.forEach((x) => console.log(x));
	console.log('TOTAL ' + issues.length);
	await browser.close();
})().catch((e) => {
	console.error(e.message);
	process.exit(1);
});
