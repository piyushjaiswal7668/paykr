package com.paykr.observer;
public final class ProtocolTest {
    public static void main(String[] args)throws Exception {
        if(args.length==3){System.out.println(Protocol.hmac(args[0],args[1],args[2]));return;}
        check(Protocol.baseUrl("https://paykr.example/").equals("https://paykr.example"));
        for(String url:new String[]{"http://localhost:8000","https://u:p@host","https://host/path","https://host?secret=x","https://host#fragment","file:///tmp/a","https://host:8443"}) {
            try{Protocol.baseUrl(url);throw new AssertionError("Accepted unsafe URL");}catch(IllegalArgumentException|java.net.URISyntaxException expected){}
        }
        check(Protocol.captureSms("VM-HDFCBK","INR 100 credited. UTR 123456789012"));
        check(!Protocol.captureSms("9876543210","INR 100 credited. UTR 123456789012"));
        check(!Protocol.captureSms("VM-HDFCBK","OTP 123456 for INR 100 credited"));
        check(!Protocol.captureSms("VM-HDFCBK","INR 100 debited"));
        check(Protocol.captureSms("9876543210","PAYKR OBSERVER TEST phone-check"));
        check(!Protocol.captureSms("9876543210","PAYKR OBSERVER TEST INR 100 credited"));
        check(Protocol.hmac("{\"rawText\":\"₹100 credited\"}","1792310000000","abcdef").equals("0ccdb2992472f4be2f5ffe8b1835ad7240f49a1c1da5298d6d143df8a2f16f2c"));
        System.out.println("Protocol validation, capture boundaries and Laravel HMAC vector passed");
    }
    private static void check(boolean condition){if(!condition)throw new AssertionError();}
}
