import { execFileSync } from "node:child_process";
import { createHmac } from "node:crypto";
import assert from "node:assert/strict";
const body = JSON.stringify({ event: "SUCCESS", metadata: { name: "पियूष" } }),
    secret = "cross-language-fixture";
const signature =
    "sha256=" + createHmac("sha256", secret).update(body).digest("hex");
const commands = [
    [process.env.PAYKR_PHP_PATH || "php", ["examples/webhooks/verify.php"]],
    ["python3", ["examples/webhooks/verify.py"]],
];
if (process.env.PAYKR_GO_PATH)
    commands.push([
        process.env.PAYKR_GO_PATH,
        ["run", "examples/webhooks/verify.go"],
    ]);
for (const [command, args] of commands) {
    for (const [raw, sig, expected] of [
        [body, signature, "valid"],
        [body + " ", signature, "invalid"],
        [body, "sha256=bad", "invalid"],
        [body, "sha256=" + "0".repeat(64), "invalid"],
    ]) {
        assert.equal(
            execFileSync(command, args, {
                input: JSON.stringify({ body: raw, signature: sig, secret }),
                encoding: "utf8",
            }),
            expected,
        );
    }
    console.log(command + ": valid/tampered/malformed/wrong-signature PASS");
}
if (!process.env.PAYKR_GO_PATH)
    console.log("Go: not run; set PAYKR_GO_PATH to enable.");
