package com.paykr.observer;

import java.net.URI;
import java.nio.charset.StandardCharsets;
import java.security.MessageDigest;
import javax.crypto.Mac;
import javax.crypto.spec.SecretKeySpec;

/** Wire rules shared with Laravel; no Android dependency, so these are JVM-testable. */
public final class Protocol {
    public static String baseUrl(String value) throws Exception {
        URI uri = new URI(value.trim());
        if (!"https".equalsIgnoreCase(uri.getScheme()) || uri.getHost() == null || uri.getUserInfo() != null
                || uri.getQuery() != null || uri.getFragment() != null || (uri.getPort() != -1 && uri.getPort() != 443)
                || !(uri.getPath().isEmpty() || "/".equals(uri.getPath()))) {
            throw new IllegalArgumentException("Use an HTTPS server origin without a path, password, query or custom port.");
        }
        return "https://" + uri.getHost().toLowerCase(java.util.Locale.ROOT);
    }
    public static String hmac(String body, String timestamp, String key) throws Exception {
        Mac mac = Mac.getInstance("HmacSHA256");
        // Laravel provisions a hex string and uses that string's UTF-8 bytes as the key.
        mac.init(new SecretKeySpec(key.getBytes(StandardCharsets.UTF_8), "HmacSHA256"));
        return hex(mac.doFinal((body + ":" + timestamp).getBytes(StandardCharsets.UTF_8)));
    }
    public static String hash(String value) throws Exception {
        return hex(MessageDigest.getInstance("SHA-256").digest(value.getBytes(StandardCharsets.UTF_8)));
    }
    private static String hex(byte[] bytes) {
        StringBuilder result = new StringBuilder();
        for (byte value : bytes) result.append(String.format(java.util.Locale.ROOT, "%02x", value & 255));
        return result.toString();
    }
    public static boolean captureSms(String sender, String text) {
        if (sender == null || sender.trim().isEmpty() || text == null || text.length() > 65536) return false;
        if (text.matches("PAYKR OBSERVER TEST [A-Za-z0-9-]{1,64}")) return true;
        if (!sender.matches("(?i)(?:[a-z]{2}-)?[a-z][a-z0-9-]{2,19}")) return false;
        return paymentText(text);
    }
    public static boolean paymentText(String text) {
        if (text == null || text.length() > 65536 || text.matches("(?is).*\\b(otp|one.time.password|verification.code)\\b.*")) return false;
        return text.matches("(?is).*\\b(credited|received|deposit|paid you)\\b.*")
            && text.matches("(?is).*(?:\\bINR|\\bRs\\.?|₹)\\s*[0-9].*");
    }
}
