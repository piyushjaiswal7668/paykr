package com.paykr.observer;

import android.content.Context;
import java.util.Set;
import java.util.HashSet;

public final class CaptureSettings {
    public static boolean enabled(Context c) { return c.getSharedPreferences("capture", 0).getBoolean("enabled", false); }
    public static void setEnabled(Context c, boolean value) { c.getSharedPreferences("capture", 0).edit().putBoolean("enabled", value).commit(); }
    public static Set<String> packages(Context c) { return new HashSet<>(c.getSharedPreferences("capture", 0).getStringSet("packages", new HashSet<>())); }
    public static void setPackages(Context c, String csv) {
        Set<String> values = new HashSet<>();
        for (String part : csv.split(",")) {
            String name = part.trim();
            if (name.matches("[A-Za-z][A-Za-z0-9_]*(\\.[A-Za-z][A-Za-z0-9_]*)+") && !name.equals(c.getPackageName())) values.add(name);
        }
        c.getSharedPreferences("capture", 0).edit().putStringSet("packages", values).commit();
    }
}
