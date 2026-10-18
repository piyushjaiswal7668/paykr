package main
import ("crypto/hmac"; "crypto/sha256"; "encoding/hex"; "encoding/json"; "errors"; "fmt"; "os"; "strings")
func verifyPaykrWebhook(raw []byte, signature, secret string) error {
 if secret == "" || !strings.HasPrefix(signature,"sha256=") || len(signature)!=71 {return errors.New("invalid signature")}
 received, err := hex.DecodeString(signature[7:]); if err!=nil {return err}
 mac:=hmac.New(sha256.New,[]byte(secret)); mac.Write(raw)
 if !hmac.Equal(mac.Sum(nil),received) || !json.Valid(raw) {return errors.New("invalid signature")}; return nil
}
func main(){var v struct {Body string; Signature string; Secret string}; if json.NewDecoder(os.Stdin).Decode(&v)!=nil {fmt.Print("invalid");return}; if verifyPaykrWebhook([]byte(v.Body),v.Signature,v.Secret)!=nil {fmt.Print("invalid")}else{fmt.Print("valid")}}
