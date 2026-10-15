/**
 * Development-only build: bundles resources/js (with vanilla-calendar-pro
 * and Chart.js) and resources/css into two self-contained files in
 * resources/dist. Consumers never run this.
 */

import { build, context } from 'esbuild';
import { mkdir, readFile, writeFile } from 'node:fs/promises';

const watch = process.argv.includes('--watch');
const banner = '/*! InertiaPixel Laravel Log Viewer | MIT License | https://github.com/inertiapixel/laravel-log-viewer */';
const thirdParty = '/*! Includes Chart.js (MIT, https://www.chartjs.org) and vanilla-calendar-pro (MIT, https://vanilla-calendar.pro) */';
const targets = ['chrome100', 'edge100', 'firefox100', 'safari15.4'];

const CSS_FILES = ['tokens.css', 'base.css', 'components.css', 'dashboard.css', 'calendar.css'];

const jsOptions = {
    entryPoints: ['resources/js/index.js'],
    outfile: 'resources/dist/inertiapixel-log-viewer.js',
    bundle: true,
    format: 'iife',
    minify: true,
    legalComments: 'none',
    target: targets,
    // The whole bundle runs in strict mode inside one IIFE.
    banner: { js: `${banner}\n${thirdParty}\n"use strict";` },
};

async function buildCss() {
    await mkdir('resources/dist', { recursive: true });

    const sources = await Promise.all(CSS_FILES.map((file) => readFile(`resources/css/${file}`, 'utf8')));
    const result = await build({
        stdin: { contents: sources.join('\n'), loader: 'css' },
        write: false,
        minify: true,
        target: targets,
        legalComments: 'none',
    });

    await writeFile('resources/dist/inertiapixel-log-viewer.css', `${banner}\n${result.outputFiles[0].text}`);
}

if (watch) {
    const ctx = await context(jsOptions);
    await ctx.watch();
    await buildCss();
    console.log('Watching resources/js (CSS is rebuilt on restart).');
} else {
    await build(jsOptions);
    await buildCss();
    console.log('Built resources/dist.');
}
