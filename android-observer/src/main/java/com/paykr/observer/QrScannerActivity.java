package com.paykr.observer;

import android.Manifest;
import android.app.Activity;
import android.content.Intent;
import android.content.pm.PackageManager;
import android.hardware.Camera;
import android.os.Bundle;
import android.view.SurfaceHolder;
import android.view.SurfaceView;
import android.view.WindowManager;
import android.widget.LinearLayout;
import android.widget.Button;
import android.widget.TextView;
import android.widget.Toast;
import com.google.zxing.BinaryBitmap;
import com.google.zxing.PlanarYUVLuminanceSource;
import com.google.zxing.common.HybridBinarizer;
import com.google.zxing.qrcode.QRCodeReader;
import java.util.List;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;

@SuppressWarnings("deprecation")
public final class QrScannerActivity extends Activity implements SurfaceHolder.Callback {
    public static final String EXTRA_QR_PAYLOAD="qr_payload";
    private final ExecutorService decoder=Executors.newSingleThreadExecutor();
    private Camera camera;
    private SurfaceView preview;
    private boolean resumed;
    @Override public void onCreate(Bundle saved) {
        super.onCreate(saved);
        getWindow().addFlags(WindowManager.LayoutParams.FLAG_SECURE);
        if(checkSelfPermission(Manifest.permission.CAMERA)!=PackageManager.PERMISSION_GRANTED) { finish(); return; }
        LinearLayout root=new LinearLayout(this); root.setOrientation(LinearLayout.VERTICAL);
        TextView title=new TextView(this); title.setText("Scan the pairing QR from your trusted merchant dashboard. You will review the server before connecting."); title.setTextSize(18); title.setPadding(20,24,20,24); root.addView(title);
        preview=new SurfaceView(this); root.addView(preview,new LinearLayout.LayoutParams(-1,0,1)); preview.getHolder().addCallback(this);
        Button cancel=new Button(this); cancel.setText("Cancel"); cancel.setOnClickListener(v -> finish()); root.addView(cancel); setContentView(root);
    }
    @Override protected void onResume() { super.onResume(); resumed=true; if(preview!=null&&preview.getHolder().getSurface().isValid()) open(preview.getHolder()); }
    @Override protected void onPause() { resumed=false; release(); super.onPause(); }
    @Override protected void onDestroy() { decoder.shutdownNow(); super.onDestroy(); }
    @Override public void surfaceCreated(SurfaceHolder holder) { open(holder); }
    @Override public void surfaceChanged(SurfaceHolder holder,int format,int w,int h) { if(camera==null) open(holder); }
    @Override public void surfaceDestroyed(SurfaceHolder holder) { release(); }
    private void open(SurfaceHolder holder) {
        if(!resumed||camera!=null) return;
        try {
            camera=Camera.open(); Camera.Parameters p=camera.getParameters();
            List<String> modes=p.getSupportedFocusModes();
            if(modes!=null&&modes.contains(Camera.Parameters.FOCUS_MODE_CONTINUOUS_PICTURE)) p.setFocusMode(Camera.Parameters.FOCUS_MODE_CONTINUOUS_PICTURE);
            List<Camera.Size> sizes=p.getSupportedPreviewSizes();
            if(sizes!=null) for(Camera.Size s:sizes) if(s.width>=640&&s.width<=1280) { p.setPreviewSize(s.width,s.height); break; }
            camera.setParameters(p); camera.setDisplayOrientation(90); camera.setPreviewDisplay(holder); camera.startPreview(); nextFrame();
        } catch(Exception e) { release(); Toast.makeText(this,"Camera unavailable. You can paste the pairing QR JSON instead.",Toast.LENGTH_LONG).show(); finish(); }
    }
    private void nextFrame() {
        if(!resumed||camera==null||isFinishing()) return;
        final Camera current=camera;
        try {
            final Camera.Size size=current.getParameters().getPreviewSize();
            current.setOneShotPreviewCallback((bytes,sourceCamera) -> {
                if(!resumed||sourceCamera!=camera||decoder.isShutdown()) return;
                decoder.execute(() -> {
                    String payload=null;
                    try {
                        PlanarYUVLuminanceSource source=new PlanarYUVLuminanceSource(bytes,size.width,size.height,0,0,size.width,size.height,false);
                        payload=new QRCodeReader().decode(new BinaryBitmap(new HybridBinarizer(source))).getText();
                    } catch(Exception ignored) {}
                    final String result=payload;
                    runOnUiThread(() -> {
                        if(!resumed||current!=camera||isFinishing()) return;
                        if(result!=null&&!result.trim().isEmpty()&&result.length()<=16384) {
                            setResult(RESULT_OK,new Intent().putExtra(EXTRA_QR_PAYLOAD,result)); finish();
                        } else nextFrame();
                    });
                });
            });
        } catch(Exception e) { release(); }
    }
    private void release() {
        Camera old=camera; camera=null;
        if(old!=null) { try { old.setOneShotPreviewCallback(null); old.stopPreview(); } catch(Exception ignored) {} try { old.release(); } catch(Exception ignored) {} }
    }
}
