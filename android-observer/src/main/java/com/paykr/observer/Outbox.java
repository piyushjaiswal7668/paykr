package com.paykr.observer;

import android.content.Context;
import android.content.ContentValues;
import android.database.Cursor;
import android.database.sqlite.SQLiteDatabase;
import android.database.sqlite.SQLiteOpenHelper;
import org.json.JSONObject;

/** Bounded encrypted queue. Never acknowledge network delivery before a server success. */
final class Outbox extends SQLiteOpenHelper {
    private static Outbox instance;
    private Outbox(Context c){super(c.getApplicationContext(),"observations.db",null,1);}
    private static synchronized Outbox get(Context c){if(instance==null)instance=new Outbox(c);return instance;}
    public void onCreate(SQLiteDatabase db){db.execSQL("CREATE TABLE pending (id TEXT PRIMARY KEY, device TEXT NOT NULL, sealed TEXT NOT NULL, created INTEGER NOT NULL, attempt INTEGER NOT NULL DEFAULT 0, next_at INTEGER NOT NULL DEFAULT 0, failed INTEGER NOT NULL DEFAULT 0)");}
    public void onUpgrade(SQLiteDatabase db,int oldVersion,int newVersion){throw new IllegalStateException("Unsupported queue version");}
    static synchronized void enqueue(Context c,String sender,String text,long observedAt,String appPackage,String expectedDevice) {
        if(!CaptureSettings.enabled(c))return;
        ApiClient api=new ApiClient(c);String device=api.getDeviceId();if(device.isEmpty() || !device.equals(expectedDevice))return;
        try {
            String id=Protocol.hash(device+"\n"+sender+"\n"+observedAt+"\n"+text);
            JSONObject event=new JSONObject().put("sender",sender).put("rawText",text).put("observedAt",observedAt).put("messageHash",id);
            if(appPackage!=null)event.put("rawNotification",new JSONObject().put("package",appPackage).put("text",text));
            SQLiteDatabase db=get(c).getWritableDatabase();
            db.delete("pending","created < ?",new String[]{String.valueOf(System.currentTimeMillis()-3*24*60*60*1000L)});
            try(Cursor rows=db.rawQuery("SELECT COUNT(*) FROM pending",null)) {
                rows.moveToFirst();if(rows.getInt(0)>=500){ApiClient.lastError="Pending queue is full. Restore the server connection.";return;}
            }
            ContentValues row=new ContentValues();row.put("id",id);row.put("device",device);row.put("sealed",Vault.encrypt(event.toString()));row.put("created",System.currentTimeMillis());
            db.insertWithOnConflict("pending",null,row,SQLiteDatabase.CONFLICT_IGNORE);
        }catch(Exception e){ApiClient.lastError="Could not securely store observation. Open Paykr to check enrollment.";}
    }
    static void drain(Context c) {
        if(!CaptureSettings.enabled(c))return;
        ApiClient api=new ApiClient(c);
        java.util.List<String[]> batch=new java.util.ArrayList<>();
        synchronized(Outbox.class) {
            SQLiteDatabase db=get(c).getWritableDatabase();
            db.delete("pending","created < ?",new String[]{String.valueOf(System.currentTimeMillis()-3*24*60*60*1000L)});
            try(Cursor rows=db.rawQuery("SELECT id,device,sealed,attempt FROM pending WHERE failed=0 AND next_at<=? ORDER BY created LIMIT 5",new String[]{String.valueOf(System.currentTimeMillis())})) {
                while(rows.moveToNext())batch.add(new String[]{rows.getString(0),rows.getString(1),rows.getString(2),rows.getString(3)});
            }
        }
        for(String[] row:batch) {
            if(!CaptureSettings.enabled(c))return;
            String id=row[0];int attempt=Integer.parseInt(row[3]);ApiClient.Response result;
            try {result=api.sendEvent(Vault.decrypt(row[2]),row[1]);}
            catch(Exception e){result=new ApiClient.Response(422,"");}
            synchronized(Outbox.class) {
                SQLiteDatabase db=get(c).getWritableDatabase();
                if(result.ok()){db.delete("pending","id=?",new String[]{id});continue;}
                ContentValues update=new ContentValues();update.put("attempt",attempt+1);
                update.put("next_at",System.currentTimeMillis()+Math.min(3600,30L*(1L<<Math.min(attempt,7)))*1000);
                if(result.status>=400 && result.status<500 && result.status!=401 && result.status!=403 && result.status!=408 && result.status!=429)update.put("failed",1);
                db.update("pending",update,"id=?",new String[]{id});
            }
        }
    }
    static synchronized String counts(Context c) {
        try(Cursor rows=get(c).getReadableDatabase().rawQuery("SELECT COUNT(*),COALESCE(SUM(failed),0) FROM pending",null)) {
            rows.moveToFirst();return "Queued: "+rows.getInt(0)+"; rejected: "+rows.getInt(1)+". Retention: 3 days, maximum 500 messages.";
        }catch(Exception e){return "Queue status unavailable.";}
    }
    static synchronized void clear(Context c){get(c).getWritableDatabase().delete("pending",null,null);}
}
