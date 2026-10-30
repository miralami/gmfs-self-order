import { chromium } from 'playwright';
import fs from 'fs';

const dir = process.env.TEMP + '\\olib-state-test2';
const state = process.env.TEMP + '\\olib-state-test2.json';
fs.rmSync(dir, { recursive: true, force: true });
fs.rmSync(state, { force: true });

// 1) simpan
let ctx = await chromium.launchPersistentContext(dir, { headless: true });
await ctx.addCookies([{ name: 'sessioncookie', value: 'abc123', domain: '.telkomuniversity.ac.id', path: '/' }]);
await ctx.storageState({ path: state });
await ctx.close();

// 2) restore manual via addCookies
const saved = JSON.parse(fs.readFileSync(state, 'utf8'));
ctx = await chromium.launchPersistentContext(dir, { headless: true });
await ctx.addCookies(saved.cookies);
let cookies = await ctx.cookies();
console.log('addCookies restore:', cookies.some((c) => c.name === 'sessioncookie' && c.value === 'abc123'));
await ctx.close();

// 3) restore via opsi storageState
ctx = await chromium.launchPersistentContext(dir, { headless: true, storageState: state });
cookies = await ctx.cookies();
console.log('storageState option restore:', cookies.some((c) => c.name === 'sessioncookie' && c.value === 'abc123'));
await ctx.close();

fs.rmSync(dir, { recursive: true, force: true });
fs.rmSync(state, { force: true });
