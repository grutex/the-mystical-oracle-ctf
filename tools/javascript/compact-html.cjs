// Keep comments, PHP, attributes and whitespace-sensitive elements verbatim.
function compactHtml(source) {
 const parts = [];
 const masked = source.replace(/<!--[\s\S]*?-->|<\?[\s\S]*?(?:\?>|$)|<(script|style|pre|textarea)\b[^>]*>[\s\S]*?<\/\1\s*>|<[^>"']*(?:"[^"]*"[^>"']*|'[^']*'[^>"']*)*>/gi, value => {
  const token = `\u0000ORACLE_HTML_${parts.length}\u0000`;
  parts.push(value); return token;
 });
 return masked.replace(/\s+/g, ' ').trim().replace(/\u0000ORACLE_HTML_(\d+)\u0000/g, (_, i) => parts[Number(i)]);
}
module.exports = { compactHtml };
