// Build-time transformation only: no runtime service or source maps.
const fs = require('node:fs');
const { compactHtml } = require('./compact-html.cjs');
const path = require('node:path');
const crypto = require('node:crypto');
const acorn = require('acorn');
const Obfuscator = require('javascript-obfuscator');

function parse(source) {
    return acorn.parse(source, { ecmaVersion: 'latest', sourceType: 'script', allowHashBang: true });
}
function strings(tree) {
    const values = new Set();
    function visit(node) {
        if (!node || typeof node !== 'object') return;
        if (node.type === 'Literal' && typeof node.value === 'string') values.add(node.value);
        if (node.type === 'TemplateElement') values.add(node.value.cooked ?? node.value.raw);
        for (const value of Object.values(node)) {
            if (Array.isArray(value)) value.forEach(visit);
            else if (value && typeof value === 'object') visit(value);
        }
    }
    visit(tree);
    return values;
}
function obfuscate(source, label, target = 'browser') {
    const comments = [];
    acorn.parse(source, { ecmaVersion: 'latest', sourceType: 'script', allowHashBang: true,
        onComment: (block, text, start, end) => comments.push(source.slice(start, end)) });
    const before = strings(parse(source));
    let output = Obfuscator.obfuscate(source, {
        target, compact: true, seed: 20261117,
        identifierNamesGenerator: 'hexadecimal',
        identifiersPrefix: 'o' + crypto.createHash('sha256').update(label).digest('hex').slice(0, 10),
        renameGlobals: false, renameProperties: false,
        reservedNames: ['^__ORACLE_PHP_[0-9]+__$'],
        stringArray: false, splitStrings: false, unicodeEscapeSequence: false,
        transformObjectKeys: false, numbersToExpressions: false, simplify: false,
        controlFlowFlattening: false, deadCodeInjection: false,
        debugProtection: false, selfDefending: false, disableConsoleOutput: false,
        sourceMap: false,
        // Comments can carry deliberate clues, especially in the Air challenge.
        conditionalComments: ['(?!)']
    }).getObfuscatedCode();
    // The obfuscator escapes spaces and renders numbers in hexadecimal even
    // with its string array disabled. Restore readable literals for CTF clues.
    const literals = [];
    function collect(node) {
        if (!node || typeof node !== 'object') return;
        if (node.type === 'Literal' && ['string', 'number'].includes(typeof node.value)) {
            literals.push([node.start, node.end, JSON.stringify(node.value)]);
        }
        for (const value of Object.values(node)) {
            if (Array.isArray(value)) value.forEach(collect);
            else if (value && typeof value === 'object') collect(value);
        }
    }
    collect(parse(output));
    for (const [start, end, value] of literals.sort((a, b) => b[0] - a[0])) {
        output = output.slice(0, start) + value + output.slice(end);
    }
    // Preserve deliberate comments exactly, including archive-discovery hints.
    output = comments.map(comment => comment + '\n').join('') + output;
    const after = strings(parse(output));
    for (const value of before) {
        if (value && !after.has(value)) throw new Error(`${label}: a string or template clue changed: ${value.slice(0, 80)}`);
    }
    return output;
}
function inlineScripts(source, label) {
    return source.replace(/(<script\b[^>]*>)([\s\S]*?)(<\/script\s*>)/gi, (all, opening, code, closing, index) => {
        if (/\bsrc\s*=/i.test(opening) || !code.trim()) return all;
        const type = opening.match(/\btype\s*=\s*["']([^"']+)["']/i)?.[1];
        if (type && !['text/javascript', 'application/javascript'].includes(type)) {
            if (type === 'module') throw new Error(`${label}: module scripts require separate module support`);
            return all;
        }
        const php = [];
        const masked = code.replace(/<\?(?:=|php\b)[\s\S]*?\?>/gi, value => {
            if (!/^<\?=/.test(value)) throw new Error(`${label}: non-expression PHP in script`);
            const token = `__ORACLE_PHP_${php.length}__`;
            php.push([token, value]);
            return token;
        });
        let output = obfuscate(masked, `${label}:${index}`);
        for (const [token, expression] of php) {
            if (output.split(token).length !== 2) throw new Error(`${label}: PHP placeholder was modified`);
            output = output.replace(token, () => expression);
        }
        // Never let a string in generated JavaScript terminate its HTML script tag.
        return opening + output.replace(/<\/script/gi, '<\\/script') + closing;
    });
}
let count = 0;
function processFile(file) {
    const source = fs.readFileSync(file, 'utf8');
    let output;
    if (/\.js(?:\.template)?$/.test(file)) {
        output = obfuscate(source, file, file.includes(`${path.sep}bot${path.sep}`) ? 'node' : 'browser');
    } else if (/\.(?:html|php)$/.test(file) && /<script\b/i.test(source)) {
        // This PHP statement builds JavaScript using concatenated PHP values;
        // handle it explicitly so the surrounding PHP string is never parsed as JS.
        if (file.endsWith(`${path.sep}challenge${path.sep}page.php`)) {
            const pattern = /\$resetStorage = '[^\r\n]+';/;
            const statement = source.match(pattern)?.[0];
            if (!statement) throw new Error('Missing progress bootstrap in ' + file);
            const prefix = "$resetStorage = '<script>";
            const suffix = "</script>';";
            if (!statement.startsWith(prefix) || !statement.endsWith(suffix)) throw new Error('Unexpected progress bootstrap');
            const js = statement.slice(prefix.length, -suffix.length).replace("' . $progress . '", '__ORACLE_PHP_0__');
            const result = obfuscate(js, file + ':progress');
            if (result.split('__ORACLE_PHP_0__').length !== 2) throw new Error('Progress placeholder changed');
            // Escape the generated source for a PHP single-quoted literal.
            const phpLiteral = result.replace(/\\/g, '\\\\').replace(/'/g, "\\'")
                .replace('__ORACLE_PHP_0__', "' . $progress . '");
            output = source.replace(statement, () => prefix + phpLiteral + suffix);
        } else {
            const phpBlocks = [];
            const masked = source.replace(/<\?php[\s\S]*?(?:\?>|$)/gi, value => {
                const token = `ORACLE_SERVER_BLOCK_${phpBlocks.length}__`;
                phpBlocks.push([token, value]);
                return token;
            });
            output = inlineScripts(masked, file);
            for (const [token, value] of phpBlocks) output = output.replace(token, () => value);
        }
    } else if (/\.html$/.test(file)) output = source;
    else return;
    if (/\.html$/.test(file)) output = compactHtml(output);
    if (output !== source) { fs.writeFileSync(file, output); count++; }
}
function walk(directory) {
    for (const entry of fs.readdirSync(directory, { withFileTypes: true })) {
        if (['tests', 'node_modules', '.git'].includes(entry.name)) continue;
        const file = path.join(directory, entry.name);
        if (entry.isDirectory()) walk(file);
        else if (entry.isFile()) {
            try { processFile(file); } catch (error) { throw new Error(file + ': ' + error.message, { cause: error }); }
        }
    }
}
const roots = process.argv.slice(2);
if (!roots.length) throw new Error('Usage: node obfuscate.cjs <build-directory> [...] (modifies generated copies only)');
for (const directory of roots) walk(path.resolve(directory));
console.log(`Obfuscated and syntax-checked ${count} JavaScript files/script containers; strings preserved.`);
