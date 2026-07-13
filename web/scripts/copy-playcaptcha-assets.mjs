/**
 * 把 playcaptcha（github.com/mortspace/playcaptcha）自带的静态素材
 * 复制到 public/ —— 按其 README 要求："copy the assets/ folder into
 * whatever your app serves statically"。组件默认在 /toys/ 下找玩具渲染图、
 * 在 /playcaptcha.svg 找 logo。npm install 后自动执行（postinstall）。
 */
import { cpSync, existsSync, mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = dirname(dirname(fileURLToPath(import.meta.url)));
const assets = join(root, 'node_modules', 'playcaptcha', 'assets');

if (!existsSync(assets)) {
    console.log('[playcaptcha] assets 目录不存在（包未安装？），跳过复制。');
    process.exit(0);
}

const publicDir = join(root, 'public');
mkdirSync(publicDir, { recursive: true });

if (existsSync(join(assets, 'toys'))) {
    cpSync(join(assets, 'toys'), join(publicDir, 'toys'), { recursive: true });
    console.log('[playcaptcha] toys/ → public/toys/');
}
if (existsSync(join(assets, 'playcaptcha.svg'))) {
    cpSync(join(assets, 'playcaptcha.svg'), join(publicDir, 'playcaptcha.svg'));
    console.log('[playcaptcha] playcaptcha.svg → public/playcaptcha.svg');
}
