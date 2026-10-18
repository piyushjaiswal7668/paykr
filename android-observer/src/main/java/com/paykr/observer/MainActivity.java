package com.paykr.observer;

import android.Manifest;
import android.app.Activity;
import android.app.AlertDialog;
import android.content.Intent;
import android.content.pm.PackageManager;
import android.os.Build;
import android.os.Bundle;
import android.provider.Settings;
import android.text.InputType;
import android.view.WindowManager;
import android.widget.*;
import org.json.JSONObject;
import java.util.ArrayList;
import java.util.UUID;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;

public final class MainActivity extends Activity {
    private final ExecutorService worker = Executors.newSingleThreadExecutor();
    private ApiClient api;
    private TextView status;
    private EditText url, token, merchant, name, qr, packages;
    private CheckBox consent;
    private LinearLayout content;
    private boolean busy;

    @Override public void onCreate(Bundle saved) {
        super.onCreate(saved);
        getWindow().addFlags(WindowManager.LayoutParams.FLAG_SECURE);
        api = new ApiClient(this);
        ScrollView scroll = new ScrollView(this);
        content = new LinearLayout(this); content.setOrientation(LinearLayout.VERTICAL);
        int pad = (int) (20 * getResources().getDisplayMetrics().density);
        content.setPadding(pad,pad,pad,pad); scroll.addView(content); setContentView(scroll);
        label("Paykr Observer · 2.0", 26);
        status = label("", 16);
        label("Pair this phone with your Laravel merchant. Credentials stay encrypted on this device. Pairing does not start observation.", 15);
        button("Scan merchant pairing QR", () -> {
            if (checkSelfPermission(Manifest.permission.CAMERA) != PackageManager.PERMISSION_GRANTED)
                requestPermissions(new String[]{Manifest.permission.CAMERA}, 10);
            else openScanner();
        });
        qr = field("Or paste pairing QR JSON", "", true);
        button("Review pasted pairing", () -> parseQr(qr.getText().toString()));
        url = field("HTTPS server URL", api.getBaseUrl(), false);
        token = field("Enrollment token", "", true);
        merchant = field("Merchant UUID", "", false);
        name = field("Device name", Build.MODEL, false);
        button("Review manual pairing", () -> reviewPair(url.getText().toString(), token.getText().toString(), merchant.getText().toString(), "v1", 0));
        label("Observation controls", 22);
        consent = new CheckBox(this);
        consent.setText("I authorize forwarding bank/payment SMS sender, complete text and timestamp to the paired merchant server. Selected payment-app notifications may also be forwarded when enabled below. Stop pauses capture and delivery; unpair clears local credentials and pending messages.");
        content.addView(consent);
        label("Optional notification apps: enter only trusted payment app package IDs, separated by commas. Empty means notification capture is disabled. Android notification access exposes notifications to the app; Paykr forwards only the configured apps.", 14);
        packages = field("Allowed package IDs (optional)", String.join(",", CaptureSettings.packages(this)), false);
        button("Save notification app selection", () -> {
            String csv = packages.getText().toString().trim();
            if (!csv.isEmpty()) for (String p : csv.split(",", -1)) {
                if (!p.trim().matches("[A-Za-z][A-Za-z0-9_]*(\\.[A-Za-z][A-Za-z0-9_]*)+")) { notice("Enter valid package IDs separated by commas."); return; }
            }
            if (!csv.isEmpty() && !consent.isChecked()) { notice("Review and accept the observation consent first."); return; }
            CaptureSettings.setPackages(this, csv);
            notice("Notification app selection saved.");
        });
        button("Android notification access settings", () -> {
            if (!consent.isChecked()) { notice("Review and accept the observation consent first."); return; }
            startActivity(new Intent(Settings.ACTION_NOTIFICATION_LISTENER_SETTINGS));
        });
        button("Start observation", this::startObservation);
        button("Stop observation", () -> { stopObservation(); notice("Observation stopped."); });
        button("Test signed heartbeat", () -> {
            if (!api.isPaired()) { notice("Pair this device first."); return; }
            runTask(() -> api.sendHeartbeat(), "Server accepted the signed heartbeat.");
        });
        button("Unpair and clear this device", () -> new AlertDialog.Builder(this)
            .setTitle("Unpair this phone?")
            .setMessage("This stops observation and clears local enrollment and queued observations. Revoke the old device on the Laravel server as well.")
            .setNegativeButton("Cancel", null).setPositiveButton("Unpair", (d,w) -> {
                if (busy) { notice("Wait for the current request to finish."); return; }
                stopObservation(); api.unpair(); token.setText(""); qr.setText(""); refresh();
            }).show());
        refresh();
    }
    private TextView label(String text, int size) { TextView v=new TextView(this); v.setText(text); v.setTextSize(size); v.setPadding(0,12,0,12); content.addView(v); return v; }
    private EditText field(String hint,String value,boolean secret) {
        EditText v=new EditText(this); v.setHint(hint); v.setText(value); v.setSingleLine(!hint.contains("JSON"));
        v.setInputType(InputType.TYPE_CLASS_TEXT | (secret ? InputType.TYPE_TEXT_VARIATION_PASSWORD : InputType.TYPE_TEXT_FLAG_NO_SUGGESTIONS));
        v.setSaveEnabled(false); v.setImportantForAutofill(android.view.View.IMPORTANT_FOR_AUTOFILL_NO_EXCLUDE_DESCENDANTS);
        content.addView(v); return v;
    }
    private void button(String text,Runnable action) { Button b=new Button(this); b.setText(text); b.setAllCaps(false); b.setOnClickListener(v -> action.run()); content.addView(b); }
    private void notice(String message) { Toast.makeText(this,message,Toast.LENGTH_LONG).show(); }
    private void refresh() {
        if (status != null) status.setText((api.isPaired() ? "Paired · " + api.getBaseUrl() + "\nDevice: " + api.getDeviceId() : "Not paired") + "\nObservation: " + (CaptureSettings.enabled(this) ? "enabled" : "stopped") + "\nSMS permission: " + (checkSelfPermission(Manifest.permission.RECEIVE_SMS)==PackageManager.PERMISSION_GRANTED ? "granted" : "not granted") + "\n" + Outbox.counts(this) + "\n" + ApiClient.lastError + "\nUse signed heartbeat to check server connectivity.");
    }
    private void parseQr(String payload) {
        try {
            if (payload.length()>16384) throw new IllegalArgumentException();
            JSONObject o=new JSONObject(payload);
            if (!"PAYKR_SMS_GATEWAY_PAIR".equals(o.getString("type")) || o.getLong("expiresAt") <= System.currentTimeMillis()) throw new IllegalArgumentException();
            reviewPair(o.optString("serverUrl",o.optString("gatewayUrl",o.optString("apiBaseUrl",""))),o.optString("enrollmentToken",o.optString("token","")),o.getString("merchantId"),o.getString("version"),o.getLong("expiresAt"));
        } catch (Exception e) { notice("Invalid pairing QR. Generate a fresh QR in your merchant dashboard."); }
    }
    private void reviewPair(String base,String enrollment,String merchantId,String version,long expiry) {
        if (busy) { notice("A request is already running."); return; }
        if (api.isPaired()) { notice("Unpair the current enrollment before pairing another merchant or server."); return; }
        try {
            final String clean=Protocol.baseUrl(base); final String secret=enrollment.trim(); final String mid=merchantId.trim();
            if (!UUID.fromString(mid).toString().equalsIgnoreCase(mid) || !"v1".equals(version) || secret.isEmpty() || secret.length()>4096 || (expiry!=0&&expiry<=System.currentTimeMillis())) throw new IllegalArgumentException();
            new AlertDialog.Builder(this).setTitle("Confirm merchant destination")
                .setMessage("Server: "+clean+"\nMerchant: "+mid+"\n\nOnly continue if this is your trusted Paykr server. Pairing enrolls this phone with the displayed merchant.")
                .setNegativeButton("Cancel",null).setPositiveButton("Pair",(d,w) -> {
                    stopObservation(); consent.setChecked(false);
                    final String deviceName=name.getText().toString().trim();
                    runTask(() -> api.pair(clean,secret,deviceName,mid,version,expiry), "Paired. Review consent and press Start observation when ready.");
                }).show();
        } catch (Exception e) { notice("Use a valid HTTPS server origin, merchant UUID and unexpired enrollment token."); }
    }
    private interface Operation { boolean run(); }
    private void runTask(Operation operation,String success) {
        if (busy) { notice("A request is already running."); return; }
        busy=true;
        worker.execute(() -> { boolean ok=false; try { ok=operation.run(); } catch (Exception ignored) {} final boolean result=ok;
            runOnUiThread(() -> { busy=false; if(isFinishing()||isDestroyed()) return; if(result) { token.setText(""); qr.setText(""); } notice(result?success:"Request failed. Check the server connection and enrollment status."); refresh(); });
        });
    }
    private void startObservation() {
        if (busy) { notice("Wait for the current request to finish."); return; }
        if (!api.isPaired() || !consent.isChecked()) { notice("Pair this device and accept observation consent first."); return; }
        ArrayList<String> permissions=new ArrayList<>();
        if(checkSelfPermission(Manifest.permission.RECEIVE_SMS)!=PackageManager.PERMISSION_GRANTED) permissions.add(Manifest.permission.RECEIVE_SMS);
        if(Build.VERSION.SDK_INT>=33&&checkSelfPermission(Manifest.permission.POST_NOTIFICATIONS)!=PackageManager.PERMISSION_GRANTED) permissions.add(Manifest.permission.POST_NOTIFICATIONS);
        if(!permissions.isEmpty()) { requestPermissions(permissions.toArray(new String[0]),11); return; }
        CaptureSettings.setEnabled(this,true); ObserverService.start(this); refresh();
    }
    private void stopObservation() { CaptureSettings.setEnabled(this,false); stopService(new Intent(this,ObserverService.class)); refresh(); }
    private void openScanner() { startActivityForResult(new Intent(this,QrScannerActivity.class),20); }
    @Override public void onRequestPermissionsResult(int code,String[] p,int[] results) { super.onRequestPermissionsResult(code,p,results); if(code==10&&checkSelfPermission(Manifest.permission.CAMERA)==PackageManager.PERMISSION_GRANTED) openScanner(); else if(code==11) { notice("Permissions updated. Press Start observation to continue."); refresh(); } }
    @Override protected void onActivityResult(int request,int result,Intent data) { super.onActivityResult(request,result,data); if(request==20&&result==RESULT_OK&&data!=null) parseQr(data.getStringExtra(QrScannerActivity.EXTRA_QR_PAYLOAD)); }
    @Override protected void onResume() { super.onResume(); if(api!=null) refresh(); }
    @Override protected void onDestroy() { worker.shutdown(); super.onDestroy(); }
}
