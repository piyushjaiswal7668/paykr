import { build } from "esbuild";
import { readFile, writeFile, mkdir } from "node:fs/promises";
import { createHash } from "node:crypto";
await mkdir("public/sdk", { recursive: true });
await build({
    entryPoints: ["resources/sdk/paykr.js"],
    outfile: "public/sdk/paykr.min.js",
    minify: true,
    target: "es2020",
});
const bytes = await readFile("public/sdk/paykr.min.js");
await writeFile(
    "public/sdk/integrity.json",
    JSON.stringify({
        integrity:
            "sha384-" + createHash("sha384").update(bytes).digest("base64"),
    }),
);

// Package the same reviewed browser implementation as ESM, preserving legacy named exports.
const source = await readFile("resources/sdk/paykr.js", "utf8");
await writeFile(
    "packages/merchant-sdk/src/paykr-checkout.js",
    source +
        "\nexport const PaykrCheckout = globalThis.PaykrCheckout;\nexport const Paykr = PaykrCheckout;\nexport default PaykrCheckout;\n",
);
