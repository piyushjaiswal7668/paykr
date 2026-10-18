package com.paykr.observer;
import android.app.Instrumentation;
import android.content.Context;
import android.os.Bundle;
import android.database.Cursor;
import android.database.sqlite.SQLiteDatabase;
import org.json.JSONObject;
public final class DeviceChecks extends Instrumentation {
 public void onCreate(Bundle args){super.onCreate(args);start();}
 public void onStart(){Bundle result=new Bundle();Context c=getTargetContext();
  try {
   String secret="test-only-never-a-real-key";
   String sealed=Vault.encrypt(secret);
   check(!sealed.contains(secret)&&Vault.decrypt(sealed).equals(secret),"Keystore roundtrip");
   JSONObject record=new JSONObject().put("url","https://example.invalid").put("deviceId","device-a").put("signingKey",secret).put("merchantId","test");
   c.getSharedPreferences("paykr_v2",0).edit().putString("enrollment",Vault.encrypt(record.toString())).commit();
   CaptureSettings.setEnabled(c,true);Outbox.clear(c);
   Outbox.enqueue(c,"VM-HDFCBK","INR 100 credited test",123L,null,"device-old");
   check(Outbox.counts(c).startsWith("Queued: 0;"),"Enrollment boundary");
   Outbox.enqueue(c,"VM-HDFCBK","INR 100 credited test",123L,null,"device-a");
   Outbox.enqueue(c,"VM-HDFCBK","INR 100 credited test",123L,null,"device-a");
   check(Outbox.counts(c).startsWith("Queued: 1;"),"Deduplication");
   try(SQLiteDatabase db=SQLiteDatabase.openDatabase(c.getDatabasePath("observations.db").getPath(),null,SQLiteDatabase.OPEN_READONLY); Cursor rows=db.rawQuery("SELECT sealed FROM pending",null)){
    rows.moveToFirst();check(!rows.getString(0).contains("credited"),"Queue encryption");
    check(new JSONObject(Vault.decrypt(rows.getString(0))).getLong("observedAt")==123L,"Original observation time");
   }
   CaptureSettings.setEnabled(c,false);
   Outbox.enqueue(c,"VM-HDFCBK","INR 200 credited",124L,null,"device-a");
   check(Outbox.counts(c).startsWith("Queued: 1;"),"Stop capture");
   new ApiClient(c).unpair();check(!new ApiClient(c).isPaired()&&Outbox.counts(c).startsWith("Queued: 0;"),"Unpair cleanup");
   result.putString("stream","PASS: Keystore, encrypted queue, enrollment isolation, dedupe, observation time, stop and unpair\n");finish(-1,result);
  }catch(Throwable e){result.putString("stream","FAIL: "+e.toString()+"\n");finish(0,result);}
 }
 private static void check(boolean ok,String name){if(!ok)throw new AssertionError(name);}
}
