package com.paykr.observer;

import android.app.Service;
import android.app.Notification;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.PendingIntent;
import android.content.Context;
import android.content.Intent;
import android.content.pm.ServiceInfo;
import android.os.Build;
import android.os.IBinder;
import java.util.concurrent.Executors;
import java.util.concurrent.ScheduledExecutorService;
import java.util.concurrent.TimeUnit;

public final class ObserverService extends Service {
    private ScheduledExecutorService worker;
    public void onCreate() {
        super.onCreate();
        NotificationManager manager=getSystemService(NotificationManager.class);
        manager.createNotificationChannel(new NotificationChannel("observer","Paykr observation",NotificationManager.IMPORTANCE_LOW));
        PendingIntent open=PendingIntent.getActivity(this,0,new Intent(this,MainActivity.class),PendingIntent.FLAG_IMMUTABLE|PendingIntent.FLAG_UPDATE_CURRENT);
        PendingIntent stop=PendingIntent.getService(this,1,new Intent(this,ObserverService.class).setAction("STOP"),PendingIntent.FLAG_IMMUTABLE|PendingIntent.FLAG_UPDATE_CURRENT);
        Notification notice=new Notification.Builder(this,"observer").setContentTitle("Paykr observer running")
            .setContentText("Observing bank SMS and selected payment apps").setSmallIcon(android.R.drawable.stat_notify_chat)
            .setContentIntent(open).addAction(new Notification.Action.Builder(null,"Stop",stop).build()).setOngoing(true).setVisibility(Notification.VISIBILITY_PRIVATE).build();
        try {
            if(Build.VERSION.SDK_INT>=34)startForeground(1001,notice,ServiceInfo.FOREGROUND_SERVICE_TYPE_SPECIAL_USE);else startForeground(1001,notice);
        } catch(RuntimeException e) {
            CaptureSettings.setEnabled(this,false);
            ApiClient.lastError="Android blocked the foreground observer. Check permissions and reopen Paykr.";
            stopSelf();
        }
    }
    public int onStartCommand(Intent intent,int flags,int id) {
        if(intent!=null && "STOP".equals(intent.getAction())){CaptureSettings.setEnabled(this,false);stopSelf();return START_NOT_STICKY;}
        if(!CaptureSettings.enabled(this) || !new ApiClient(this).isPaired()){stopSelf();return START_NOT_STICKY;}
        if(worker==null) {
            worker=Executors.newSingleThreadScheduledExecutor();
            worker.scheduleWithFixedDelay(()->{
                try {
                    if(!CaptureSettings.enabled(this) || !new ApiClient(this).isPaired()){stopSelf();return;}
                    new ApiClient(this).sendHeartbeat();
                    if(!Thread.currentThread().isInterrupted())Outbox.drain(this);
                } catch(Exception e) { ApiClient.lastError="Observation worker could not complete. Pending messages remain queued."; }
            },0,30,TimeUnit.SECONDS);
        }
        return START_STICKY;
    }
    public void onDestroy(){if(worker!=null)worker.shutdownNow();super.onDestroy();}
    public IBinder onBind(Intent intent){return null;}
    public static void start(Context context) {
        if(!CaptureSettings.enabled(context) || !new ApiClient(context).isPaired())return;
        try{context.startForegroundService(new Intent(context,ObserverService.class));}
        catch(RuntimeException e){CaptureSettings.setEnabled(context,false);ApiClient.lastError="Open Paykr and tap Start to resume background observation.";}
    }
}
