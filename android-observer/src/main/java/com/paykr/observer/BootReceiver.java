package com.paykr.observer;
import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;
public final class BootReceiver extends BroadcastReceiver {
    public void onReceive(Context context,Intent intent){if(intent!=null && Intent.ACTION_BOOT_COMPLETED.equals(intent.getAction()))ObserverService.start(context);}
}
