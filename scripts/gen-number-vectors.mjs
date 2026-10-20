// Generates tests/fixtures/jcs-numbers.vectors.json with Node (V8 = ECMAScript reference).
// Usage: node scripts/gen-number-vectors.mjs > tests/fixtures/jcs-numbers.vectors.json
// Each entry is the ECMAScript spelling of a double; canonicalizing that text must return it unchanged.
const out = [];
const buf = new DataView(new ArrayBuffer(8));
let seed = 123456789;
const rnd = () => (seed = (Math.imul(seed, 1664525) + 1013904223) >>> 0) / 4294967296;
const push = (x) => { if (Number.isFinite(x) && x !== 0) out.push(String(x)); };
for (let i = 0; i < 1500; i++) { // random bit patterns: every exponent range
  buf.setUint32(0, Math.floor(rnd() * 4294967296)); buf.setUint32(4, Math.floor(rnd() * 4294967296));
  push(buf.getFloat64(0));
}
for (let i = 0; i < 600; i++) push(Math.round(rnd() * 1e10) / 1e8); // money-like, 8 decimals
for (let i = 0; i < 300; i++) push(Math.round(rnd() * 1e6) / 100);   // money-like, 2 decimals
for (let e = -330; e <= 308; e += 7) push(Number('1e' + e)); // powers of ten
for (let e = -1074; e <= 1023; e += 11) push(2 ** e);        // powers of two, asymmetric intervals
for (let e = 1; e <= 1023; e += 13) push(2 ** e - 1);        // power-of-two neighbours
console.log(JSON.stringify(out));
