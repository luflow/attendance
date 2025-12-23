#!/usr/bin/env node
/**
 * Translation file converter for Nextcloud apps
 *
 * Usage:
 *   node scripts/l10n.js convert-po   - Convert .po files to .js and .json
 *   node scripts/l10n.js export-po    - Export existing .js translations to .po files (one-time migration)
 */

import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const rootDir = path.resolve(__dirname, '..');

const APP_NAME = 'attendance';
const L10N_DIR = path.join(rootDir, 'l10n');
const TRANSLATION_FILES_DIR = path.join(rootDir, 'translationfiles');

/**
 * Parse a .po file and extract translations
 */
function parsePo(content) {
    const translations = {};
    let pluralForm = 'nplurals=2; plural=(n != 1);';

    // Extract plural form from header
    const pluralMatch = content.match(/Plural-Forms:\s*([^\\]*)/);
    if (pluralMatch) {
        pluralForm = pluralMatch[1].trim();
        if (!pluralForm.endsWith(';')) {
            pluralForm += ';';
        }
    }

    // Split into blocks (separated by blank lines)
    const blocks = content.split(/\n\n+/);

    for (const block of blocks) {
        const lines = block.split('\n');
        let msgid = '';
        let msgidPlural = '';
        let msgstr = '';
        let msgstrPlural = [];
        let inMsgid = false;
        let inMsgidPlural = false;
        let inMsgstr = false;
        let currentPluralIndex = -1;

        for (const line of lines) {
            // Skip comments and empty lines
            if (line.startsWith('#') || line.trim() === '') {
                continue;
            }

            if (line.startsWith('msgid_plural ')) {
                inMsgid = false;
                inMsgidPlural = true;
                inMsgstr = false;
                msgidPlural = extractString(line.substring(13));
            } else if (line.startsWith('msgid ')) {
                inMsgid = true;
                inMsgidPlural = false;
                inMsgstr = false;
                msgid = extractString(line.substring(6));
            } else if (line.startsWith('msgstr[')) {
                inMsgid = false;
                inMsgidPlural = false;
                inMsgstr = true;
                const indexMatch = line.match(/msgstr\[(\d+)\]\s*(.*)/);
                if (indexMatch) {
                    currentPluralIndex = parseInt(indexMatch[1]);
                    msgstrPlural[currentPluralIndex] = extractString(indexMatch[2]);
                }
            } else if (line.startsWith('msgstr ')) {
                inMsgid = false;
                inMsgidPlural = false;
                inMsgstr = true;
                currentPluralIndex = -1;
                msgstr = extractString(line.substring(7));
            } else if (line.startsWith('"')) {
                // Continuation line
                const str = extractString(line);
                if (inMsgid) {
                    msgid += str;
                } else if (inMsgidPlural) {
                    msgidPlural += str;
                } else if (inMsgstr) {
                    if (currentPluralIndex >= 0) {
                        msgstrPlural[currentPluralIndex] = (msgstrPlural[currentPluralIndex] || '') + str;
                    } else {
                        msgstr += str;
                    }
                }
            }
        }

        // Skip header block and empty msgids
        if (msgid === '' || msgid === '""') {
            continue;
        }

        // Handle plurals
        if (msgidPlural && msgstrPlural.length > 0) {
            // Nextcloud plural format: "singular::plural" => [singular_translation, plural_translation]
            const key = `_${msgid}_::_${msgidPlural}_`;
            translations[key] = msgstrPlural;
        } else if (msgstr) {
            translations[msgid] = msgstr;
        }
    }

    return { translations, pluralForm };
}

/**
 * Extract string content from a PO line (handles quoted strings)
 */
function extractString(str) {
    str = str.trim();
    if (str.startsWith('"') && str.endsWith('"')) {
        str = str.slice(1, -1);
    }
    // Unescape common escape sequences
    return str
        .replace(/\\n/g, '\n')
        .replace(/\\t/g, '\t')
        .replace(/\\"/g, '"')
        .replace(/\\\\/g, '\\');
}

/**
 * Escape string for use in JS/JSON
 */
function escapeString(str) {
    return str
        .replace(/\\/g, '\\\\')
        .replace(/"/g, '\\"')
        .replace(/\n/g, '\\n')
        .replace(/\t/g, '\\t');
}

/**
 * Generate .js file content from translations
 */
function generateJs(translations, pluralForm, lang) {
    let content = `/**
 * This file is auto-generated from translationfiles/${lang}/${APP_NAME}.po
 * Do not edit directly - edit the .po file instead and run: npm run l10n:convert
 */
OC.L10N.register(
    "${APP_NAME}",
    {\n`;

    const entries = Object.entries(translations);
    entries.forEach(([key, value], index) => {
        const escapedKey = escapeString(key);
        let escapedValue;

        if (Array.isArray(value)) {
            // Plural form
            escapedValue = '[' + value.map(v => `"${escapeString(v)}"`).join(',') + ']';
        } else {
            escapedValue = `"${escapeString(value)}"`;
        }

        const comma = index < entries.length - 1 ? ',' : '';
        content += `        "${escapedKey}": ${escapedValue}${comma}\n`;
    });

    content += `    },\n    "${pluralForm}"\n);\n`;
    return content;
}

/**
 * Generate .json file content from translations
 */
function generateJson(translations, pluralForm, lang) {
    const data = {
        _comment: `This file is auto-generated from translationfiles/${lang}/${APP_NAME}.po - Do not edit directly`,
        translations: translations,
        pluralForm: pluralForm
    };
    return JSON.stringify(data, null, 4) + '\n';
}

/**
 * Generate .po file content from translations
 */
function generatePo(translations, language, pluralForm) {
    const now = new Date().toISOString().replace('T', ' ').substring(0, 19) + '+0000';

    let content = `# Translations for ${APP_NAME}
# This file is distributed under the same license as the ${APP_NAME} package.
# FIRST AUTHOR <EMAIL@ADDRESS>, YEAR.
#
msgid ""
msgstr ""
"Project-Id-Version: ${APP_NAME}\\n"
"Report-Msgid-Bugs-To: \\n"
"POT-Creation-Date: ${now}\\n"
"PO-Revision-Date: ${now}\\n"
"Last-Translator: \\n"
"Language-Team: ${language}\\n"
"Language: ${language}\\n"
"MIME-Version: 1.0\\n"
"Content-Type: text/plain; charset=UTF-8\\n"
"Content-Transfer-Encoding: 8bit\\n"
"Plural-Forms: ${pluralForm}\\n"

`;

    for (const [key, value] of Object.entries(translations)) {
        // Handle plural forms (Nextcloud format: "_singular_::_plural_")
        const pluralMatch = key.match(/^_(.+)_::_(.+)_$/);
        if (pluralMatch && Array.isArray(value)) {
            content += `msgid "${escapePoString(pluralMatch[1])}"\n`;
            content += `msgid_plural "${escapePoString(pluralMatch[2])}"\n`;
            value.forEach((v, i) => {
                content += `msgstr[${i}] "${escapePoString(v)}"\n`;
            });
        } else {
            content += `msgid "${escapePoString(key)}"\n`;
            content += `msgstr "${escapePoString(value)}"\n`;
        }
        content += '\n';
    }

    return content;
}

/**
 * Escape string for use in .po file
 */
function escapePoString(str) {
    return str
        .replace(/\\/g, '\\\\')
        .replace(/"/g, '\\"')
        .replace(/\n/g, '\\n')
        .replace(/\t/g, '\\t');
}

/**
 * Parse existing .js translation file
 */
function parseJs(content) {
    // Extract the translations object
    const match = content.match(/OC\.L10N\.register\(\s*"[^"]+",\s*(\{[\s\S]*?\})\s*,\s*"([^"]+)"\s*\)/);
    if (!match) {
        throw new Error('Could not parse .js translation file');
    }

    const translationsStr = match[1];
    const pluralForm = match[2];

    // Parse the translations object (it's almost valid JSON)
    // We need to handle some edge cases like trailing commas and unquoted keys
    const translations = {};

    // Use a regex to extract key-value pairs
    const pairRegex = /"([^"\\]*(?:\\.[^"\\]*)*)"\s*:\s*(\[(?:[^\]]*)\]|"[^"\\]*(?:\\.[^"\\]*)*")/g;
    let pairMatch;

    while ((pairMatch = pairRegex.exec(translationsStr)) !== null) {
        const key = pairMatch[1].replace(/\\"/g, '"').replace(/\\n/g, '\n').replace(/\\\\/g, '\\');
        let value = pairMatch[2];

        if (value.startsWith('[')) {
            // Array value (plural form)
            const arrayMatch = value.match(/\["([^"\\]*(?:\\.[^"\\]*)*)"\s*,\s*"([^"\\]*(?:\\.[^"\\]*)*)"\]/);
            if (arrayMatch) {
                translations[key] = [
                    arrayMatch[1].replace(/\\"/g, '"').replace(/\\n/g, '\n').replace(/\\\\/g, '\\'),
                    arrayMatch[2].replace(/\\"/g, '"').replace(/\\n/g, '\n').replace(/\\\\/g, '\\')
                ];
            }
        } else {
            // String value
            value = value.slice(1, -1).replace(/\\"/g, '"').replace(/\\n/g, '\n').replace(/\\\\/g, '\\');
            translations[key] = value;
        }
    }

    return { translations, pluralForm };
}

/**
 * Convert .po files to .js and .json
 */
function convertPoFiles() {
    console.log('Converting .po files to .js and .json...');

    const languages = fs.readdirSync(TRANSLATION_FILES_DIR).filter(f => {
        const fullPath = path.join(TRANSLATION_FILES_DIR, f);
        return fs.statSync(fullPath).isDirectory() && f !== 'templates';
    });

    for (const lang of languages) {
        const poFile = path.join(TRANSLATION_FILES_DIR, lang, `${APP_NAME}.po`);

        if (!fs.existsSync(poFile)) {
            console.log(`  Skipping ${lang}: no .po file found`);
            continue;
        }

        console.log(`  Processing ${lang}...`);

        const poContent = fs.readFileSync(poFile, 'utf8');
        const { translations, pluralForm } = parsePo(poContent);

        // Generate .js file
        const jsContent = generateJs(translations, pluralForm, lang);
        fs.writeFileSync(path.join(L10N_DIR, `${lang}.js`), jsContent);

        // Generate .json file
        const jsonContent = generateJson(translations, pluralForm, lang);
        fs.writeFileSync(path.join(L10N_DIR, `${lang}.json`), jsonContent);

        console.log(`    Generated ${lang}.js and ${lang}.json (${Object.keys(translations).length} translations)`);
    }

    console.log('Done!');
}

/**
 * Export existing .js translations to .po files (one-time migration)
 */
function exportPoFiles() {
    console.log('Exporting existing translations to .po files...');

    const jsFiles = fs.readdirSync(L10N_DIR).filter(f => f.endsWith('.js'));

    for (const jsFile of jsFiles) {
        const lang = jsFile.replace('.js', '');
        const jsPath = path.join(L10N_DIR, jsFile);

        console.log(`  Processing ${lang}...`);

        try {
            const jsContent = fs.readFileSync(jsPath, 'utf8');
            const { translations, pluralForm } = parseJs(jsContent);

            // Create language directory
            const langDir = path.join(TRANSLATION_FILES_DIR, lang);
            if (!fs.existsSync(langDir)) {
                fs.mkdirSync(langDir, { recursive: true });
            }

            // Generate .po file
            const poContent = generatePo(translations, lang, pluralForm);
            fs.writeFileSync(path.join(langDir, `${APP_NAME}.po`), poContent);

            console.log(`    Exported ${lang}.po (${Object.keys(translations).length} translations)`);
        } catch (error) {
            console.error(`    Error processing ${lang}: ${error.message}`);
        }
    }

    console.log('Done!');
}

// Main
const command = process.argv[2];

switch (command) {
    case 'convert-po':
        convertPoFiles();
        break;
    case 'export-po':
        exportPoFiles();
        break;
    default:
        console.log('Usage:');
        console.log('  node scripts/l10n.js convert-po   - Convert .po files to .js and .json');
        console.log('  node scripts/l10n.js export-po    - Export existing .js to .po files');
        process.exit(1);
}
