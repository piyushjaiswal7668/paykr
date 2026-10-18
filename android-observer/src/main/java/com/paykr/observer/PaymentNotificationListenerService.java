package com.paykr.observer;

import android.app.Notification;
import android.os.Bundle;
import android.service.notification.NotificationListenerService;
import android.service.notification.StatusBarNotification;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;

public final class PaymentNotificationListenerService extends NotificationListenerService {
    private final ExecutorService disk=Executors.newSingleThreadExecutor();
    public void onNotificationPosted(StatusBarNotification sbn) {
        if(sbn==null || !CaptureSettings.enabled(this) || !CaptureSettings.packages(this).contains(sbn.getPackageName()))return;
        Notification n=sbn.getNotification();if(n==null || (n.flags&Notification.FLAG_GROUP_SUMMARY)!=0)return;
        Bundle extras=n.extras;if(extras==null)return;
        CharSequence title=extras.getCharSequence(Notification.EXTRA_TITLE,"");
        CharSequence text=extras.getCharSequence(Notification.EXTRA_BIG_TEXT);
        if(text==null || text.length()==0)text=extras.getCharSequence(Notification.EXTRA_TEXT,"");
        String raw=(title==null?"":title.toString())+"\n"+(text==null?"":text.toString());
        if(!Protocol.paymentText(raw))return;
        // Package identity is retained. Notification titles cannot impersonate a bank SMS sender.
        String device=new ApiClient(this).getDeviceId();
        disk.execute(()->{Outbox.enqueue(this,sbn.getPackageName(),raw,sbn.getPostTime(),sbn.getPackageName(),device);ObserverService.start(this);});
    }
    public void onDestroy(){disk.shutdown();super.onDestroy();}
}
