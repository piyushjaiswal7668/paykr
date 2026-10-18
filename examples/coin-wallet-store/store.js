const status = document.querySelector("#status");
document.querySelector("#topup").addEventListener("submit", async (event) => {
    event.preventDefault();
    const button = event.target.querySelector("button");
    button.disabled = true;
    status.textContent = "Opening secure checkout…";
    try {
        const response = await fetch("/api/checkout", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
                price: new FormData(event.target).get("price"),
            }),
        });
        const result = await response.json();
        if (!response.ok) throw new Error(result.error);
        const url = new URL(result.checkoutUrl);
        if (!["http:", "https:"].includes(url.protocol))
            throw new Error("Invalid checkout link");
        location.assign(url.href);
    } catch (error) {
        status.textContent = error.message;
        button.disabled = false;
    }
});
const orderId = new URLSearchParams(location.search).get("orderId");
if (orderId) {
    fetch("/api/verify-payment", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ orderId }),
    })
        .then((response) => response.json())
        .then((result) => {
            status.textContent =
                result.error ||
                (result.credited
                    ? "Payment verified. Coins added to your wallet."
                    : "See your wallet for the latest verified balance.");
        });
}
