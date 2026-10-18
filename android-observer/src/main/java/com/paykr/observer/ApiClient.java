package com.paykr.observer;

import android.content.Context;
import android.content.SharedPreferences;
import android.content.Intent;
import android.os.Build;
import org.json.JSONObject;
import javax.net.ssl.HttpsURLConnection;
import java.net.URL;
import java.io.InputStream;
import java.io.ByteArrayOutputStream;
import java.nio.charset.StandardCharsets;

public final class ApiClient {
    public static volatile String lastError = "";
    private final Context context;
    private final SharedPreferences prefs;
    public ApiClient(Context context) { this.context = context.getApplicationContext(); prefs = this.context.getSharedPreferences("paykr_v2", 0); }
    JSONObject credentials() {
        try { String sealed = prefs.getString("enrollment", null); return sealed == null ? null : new JSONObject(Vault.decrypt(sealed)); }
        catch (Exception e) { lastError = "Cannot unlock enrollment. Unpair and pair again."; return null; }
    }
    public boolean isPaired() { return credentials() != null; }
    public String getBaseUrl() { JSONObject c=credentials(); return c==null ? "" : c.optString("url"); }
    public String getDeviceId() { JSONObject c=credentials(); return c==null ? "" : c.optString("deviceId"); }
    public boolean pair(String url, String token, String name, String merchant, String version, long expires) {
        if (isPaired()) { lastError="Unpair before changing servers."; return false; }
        try {
            url=Protocol.baseUrl(url);
            if (!"v1".equals(version) || token == null || !token.matches("pair_[a-f0-9]{48}")
                || merchant == null || !merchant.matches("[a-fA-F0-9]{8}(?:-[a-fA-F0-9]{4}){3}-[a-fA-F0-9]{12}")
                || (expires > 0 && expires <= System.currentTimeMillis())) throw new IllegalArgumentException();
            JSONObject body=new JSONObject().put("version",version).put("enrollmentToken",token).put("merchantId",merchant)
                .put("deviceName",name==null || name.trim().isEmpty()?Build.MODEL:name).put("deviceModel",Build.MANUFACTURER+" "+Build.MODEL);
            if (expires>0) body.put("expiresAt",expires);
            Response response=post(url+"/api/v1/pollers/android/pair",body.toString(),null);
            if (!response.ok()) return false;
            JSONObject result=new JSONObject(response.body);
            if (!result.optBoolean("paired") || !result.getString("signingKey").matches("[a-f0-9]{64}")
                || !merchant.equals(result.getString("merchantId")) || !result.getString("deviceId").matches("[a-fA-F0-9-]{36}")) throw new IllegalArgumentException();
            JSONObject record=new JSONObject().put("url",url).put("deviceId",result.getString("deviceId"))
                .put("signingKey",result.getString("signingKey")).put("merchantId",merchant);
            if (!prefs.edit().putString("enrollment",Vault.encrypt(record.toString())).commit()) throw new IllegalStateException();
            lastError="Paired. Start observation when ready."; return true;
        } catch (Exception e) { lastError="Pairing failed. Check the HTTPS address, token, expiry and phone clock."; return false; }
    }
    public boolean sendHeartbeat() {
        try {
            JSONObject c=credentials(); if(c==null)return false;
            boolean ok=post(c.getString("url")+"/api/v1/pollers/android/heartbeat",new JSONObject().put("deviceId",c.getString("deviceId")).toString(),c).ok();
            if(ok) lastError="Server connection verified.";
            return ok;
        } catch(Exception e) { lastError="Heartbeat failed."; return false; }
    }
    Response sendEvent(String body, String deviceId) {
        try { JSONObject c=credentials(); if(c==null || !deviceId.equals(c.getString("deviceId")))return new Response(410,"");
            return post(c.getString("url")+"/api/v1/pollers/android/events",body,c);
        } catch(Exception e) { return new Response(0,""); }
    }
    public void unpair() {
        CaptureSettings.setEnabled(context,false); context.stopService(new Intent(context,ObserverService.class));
        synchronized(Outbox.class) { prefs.edit().clear().commit(); Outbox.clear(context); } lastError="Unpaired locally. Revoke the old device in Laravel before reusing this phone.";
    }
    private Response post(String url, String body, JSONObject credentials) {
        HttpsURLConnection connection=null;
        try {
            connection=(HttpsURLConnection)new URL(url).openConnection();
            connection.setInstanceFollowRedirects(false); connection.setConnectTimeout(3000); connection.setReadTimeout(3000);
            connection.setRequestMethod("POST"); connection.setRequestProperty("Content-Type","application/json; charset=utf-8");
            connection.setRequestProperty("Accept","application/json"); connection.setDoOutput(true);
            if(credentials!=null) {
                String timestamp=String.valueOf(System.currentTimeMillis());
                connection.setRequestProperty("X-Device-Id",credentials.getString("deviceId"));
                connection.setRequestProperty("X-Timestamp",timestamp);
                connection.setRequestProperty("X-Signature",Protocol.hmac(body,timestamp,credentials.getString("signingKey")));
            }
            byte[] bytes=body.getBytes(StandardCharsets.UTF_8); connection.setFixedLengthStreamingMode(bytes.length);
            try(java.io.OutputStream output=connection.getOutputStream()){output.write(bytes);}
            int status=connection.getResponseCode();
            if(status<200 || status>=300){lastError="Server returned HTTP "+status+". Pending messages are retained.";return new Response(status,"");}
            try(InputStream input=connection.getInputStream(); ByteArrayOutputStream output=new ByteArrayOutputStream()) {
                byte[] buffer=new byte[4096];int count;
                while((count=input.read(buffer))!=-1){if(output.size()+count>65536)throw new IllegalStateException();output.write(buffer,0,count);}
                return new Response(status,new String(output.toByteArray(),StandardCharsets.UTF_8));
            }
        } catch(Exception e){lastError="Cannot reach the HTTPS server. Check connectivity and certificate.";return new Response(0,"");}
        finally{if(connection!=null)connection.disconnect();}
    }
    static final class Response {
        final int status; final String body;
        Response(int status,String body){this.status=status;this.body=body;}
        boolean ok(){return status>=200 && status<300;}
    }
}
