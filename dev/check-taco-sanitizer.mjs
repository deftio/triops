/**
 * Adversarial tests for triops.sanitizeTaco().
 *
 *     node dev/check-taco-sanitizer.mjs
 *
 * This is the one place in triops where a device's bytes become UI rather than
 * text on a page, so it is the one place where getting it wrong is more than a
 * display bug. Every case below is something the 0.2.0 sanitiser let through:
 * it stripped on* handlers and javascript: URLs and passed the rest along, which
 * covers none of the tags that never needed a handler to begin with.
 *
 * No test framework, for the same reason the smoke test is bash and curl.
 * triops.js is a browser IIFE with no module system, so it is evaluated as-is —
 * `bw` is only touched inside the rendering functions, which these never call.
 */

import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = dirname(dirname(fileURLToPath(import.meta.url)));
const src = readFileSync(join(root, 'app/assets/triops.js'), 'utf8');

// Indirect eval, so `var triops` lands on the global object.
(0, eval)(src + ';globalThis.__triops = triops;');
const { sanitizeTaco } = globalThis.__triops;

let pass = 0;
let fail = 0;

function check(label, actual, expected) {
    const a = JSON.stringify(actual);
    const e = JSON.stringify(expected);
    if (a === e) {
        pass++;
        console.log('  ok   ' + label);
    } else {
        fail++;
        console.log('  FAIL ' + label + '\n     expected: ' + e + '\n     actual:   ' + a);
    }
}

/** The attributes that survived, for a node built around one attribute. */
function attrs(a) {
    const out = sanitizeTaco({ t: 'a', a: a, c: 'x' });
    return out === null ? null : out.a;
}

console.log('taco sanitiser — unsafe elements');
for (const tag of ['script', 'iframe', 'object', 'embed', 'form', 'input',
                   'style', 'link', 'meta', 'base', 'svg', 'math', 'marquee',
                   'template', 'textarea', 'select', 'audio', 'video', 'source']) {
    check(tag + ' is rejected', sanitizeTaco({ t: tag, c: 'x' }), null);
}
check('a script with a src is rejected',
    sanitizeTaco({ t: 'script', a: { src: 'https://evil.test/x.js' } }), null);
check('an unknown tag is rejected', sanitizeTaco({ t: 'blink', c: 'x' }), null);
check('a missing tag is rejected', sanitizeTaco({ c: 'orphan text' }), null);
check('a numeric tag is rejected', sanitizeTaco({ t: 42, c: 'x' }), null);

console.log('\ntaco sanitiser — unsafe elements nested in safe ones');
check('an iframe child is dropped, the parent survives',
    sanitizeTaco({ t: 'div', c: [{ t: 'p', c: 'ok' }, { t: 'iframe', a: { src: 'https://evil.test' } }] }),
    { t: 'div', c: [{ t: 'p', c: 'ok' }] });
check('a script buried three deep is dropped',
    sanitizeTaco({ t: 'div', c: { t: 'section', c: { t: 'script', c: 'alert(1)' } } }),
    { t: 'div', c: { t: 'section' } });

console.log('\ntaco sanitiser — case handling');
check('an uppercase safe tag is normalised', sanitizeTaco({ t: 'DIV', c: 'x' }), { t: 'div', c: 'x' });
check('an uppercase unsafe tag is still rejected', sanitizeTaco({ t: 'SCRIPT', c: 'x' }), null);
check('a mixed-case unsafe tag is still rejected', sanitizeTaco({ t: 'IfRaMe', c: 'x' }), null);

console.log('\ntaco sanitiser — attributes');
check('onclick is dropped', attrs({ onclick: 'alert(1)' }), {});
check('ONCLICK is dropped', attrs({ ONCLICK: 'alert(1)' }), {});
check('OnError is dropped', attrs({ OnError: 'alert(1)' }), {});
check('onmouseover is dropped', attrs({ onmouseover: 'alert(1)' }), {});
check('srcdoc is dropped', attrs({ srcdoc: '<script>alert(1)</script>' }), {});
check('formaction is dropped', attrs({ formaction: '/x' }), {});
check('style is dropped', attrs({ style: 'position:fixed;inset:0;z-index:99999' }), {});
check('STYLE is dropped', attrs({ STYLE: 'position:fixed' }), {});
check('an object-valued attribute is dropped', attrs({ title: { toString: 'x' } }), {});
check('an array-valued attribute is dropped', attrs({ title: ['x'] }), {});
check('a malformed attribute name is dropped', attrs({ 'a b': 'x' }), {});
check('a class is kept', attrs({ class: 'bw_act_restart' }), { class: 'bw_act_restart' });
check('a data attribute is kept', attrs({ 'data-id': 'pump-03' }), { 'data-id': 'pump-03' });
check('a numeric value is kept', attrs({ tabindex: 0 }), { tabindex: 0 });
check('a boolean value is kept', attrs({ hidden: true }), { hidden: true });

console.log('\ntaco sanitiser — URLs');
for (const url of ['javascript:alert(1)', 'JaVaScRiPt:alert(1)', ' javascript:alert(1)',
                   '\tjavascript:alert(1)', 'data:text/html;base64,PHNjcmlwdD4=',
                   'vbscript:msgbox', 'blob:https://evil.test/x', 'filesystem:https://evil.test/x',
                   'file:///etc/passwd']) {
    check('href ' + JSON.stringify(url) + ' is dropped', attrs({ href: url }), {});
}
for (const url of ['https://example.test/x', 'http://example.test/x', 'mailto:a@example.test',
                   '/relative/path', './sibling', '../parent', '#anchor', '?q=1']) {
    check('href ' + JSON.stringify(url) + ' is kept', attrs({ href: url }), { href: url });
}
check('src javascript: is dropped',
    JSON.stringify(sanitizeTaco({ t: 'img', a: { src: 'javascript:alert(1)' } })), '{"t":"img","a":{}}');
check('src https is kept',
    sanitizeTaco({ t: 'img', a: { src: 'https://example.test/a.png' } }),
    { t: 'img', a: { src: 'https://example.test/a.png' } });

console.log('\ntaco sanitiser — the options block');
check('o is never carried through',
    sanitizeTaco({ t: 'div', o: { state: { x: 1 }, render: 'function(){}' }, c: 'x' }),
    { t: 'div', c: 'x' });

console.log('\ntaco sanitiser — resource limits');
let deep = { t: 'span', c: 'bottom' };
for (let i = 0; i < 200; i++) deep = { t: 'div', c: deep };
const deepResult = sanitizeTaco(deep);
check('a 200-deep tree does not throw and is truncated',
    deepResult !== null && JSON.stringify(deepResult).length < JSON.stringify(deep).length, true);

const wide = { t: 'div', c: [] };
for (let i = 0; i < 5000; i++) wide.c.push({ t: 'span', c: String(i) });
const wideResult = sanitizeTaco(wide);
check('a 5000-child tree is capped', wideResult.c.length < 5000, true);

const longText = sanitizeTaco({ t: 'p', c: 'x'.repeat(50000) });
check('a 50k text node is truncated', longText.c.length < 50000, true);

console.log('\ntaco sanitiser — well-formed documents survive intact');
const panel = {
    t: 'div',
    a: { class: 'panel' },
    c: [
        { t: 'h3', c: 'pump-03' },
        { t: 'ul', c: [{ t: 'li', c: 'pressure: 2.4 bar' }, { t: 'li', c: 'runtime: 41h' }] },
        { t: 'button', a: { action: 'run-calibration' }, c: 'Calibrate' }
    ]
};
check('a realistic device panel is unchanged', sanitizeTaco(panel), panel);

console.log('\npassed ' + pass + ', failed ' + fail);
process.exit(fail === 0 ? 0 : 1);
