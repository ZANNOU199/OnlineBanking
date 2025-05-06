<?php

namespace App\Http\Controllers;

use App\Setting;
use CoinGate\CoinGate;
use http\Exception;
use Illuminate\Http\Request;
use App\Deposit;
use App\Transaction;
use Carbon\Carbon;
use Auth;
use Session;
use App\User;
use App\Gateway;
use Stripe\Stripe;
use Stripe\Token;
use Stripe\Charge;
use App\Lib\coinPayments;
use App\Lib\BlockIo;
use App\Lib\CoinPaymentHosted;


class PaymentController extends Controller
{

    public function userDataUpdate($data)
{ 
    // Vérification si la transaction a déjà été traitée
    if ($data->status != 0) {
        \Log::info('Deposit already processed.');
        return; // Ne rien faire si déjà traité
    }

    // Mise à jour du statut du dépôt pour marquer comme "traité"
    $data->status = 1;
    $data->save(); // Utilisation de save() pour persister la mise à jour

    // Récupérer l'utilisateur associé au dépôt
    $user = User::find($data->user_id);
    $user->balance += $data->amount;  // Ajouter le montant du dépôt au solde de l'utilisateur
    $user->save(); // Sauvegarder l'utilisateur avec le solde mis à jour

    // Enregistrement de la transaction
    $tlog = new Transaction();
    $tlog->user_id = $user->id;
    $tlog->amount = $data->amount;
    $tlog->balance = $user->balance;
    $tlog->type = 1;  // 1 pour un dépôt (selon ton modèle)
    $tlog->details = 'Deposit Via ' . $data->gateway->name;  // Détails du dépôt
    $tlog->trxid = str_random(16);  // Générez un identifiant unique pour la transaction
    $tlog->save();  // Sauvegarder la transaction dans la base de données

    // Envoi de l'email et SMS à l'utilisateur
    $msg = 'Deposit Payment Successful';
    send_email($user->email, $user->username, 'Deposit Successful', $msg);  // Envoi de l'email
    send_sms($user->mobile, $msg);  // Envoi du SMS

    \Log::info('Deposit successful for user ID: ' . $user->id);  // Log pour débogage
}


    

    
public function depositConfirm()
{
    $gnl = Setting::first();
    
    // Récupérer le Track ID depuis la session
    $track = Session::get('Track');
    \Log::info('Tracking ID: ' . $track); // Log pour vérifier la récupération du Track ID

    // Chercher la transaction basée sur le Track ID
    $data = Deposit::where('trx', $track)->orderBy('id', 'DESC')->first();
    \Log::info('Deposit Data: ' . print_r($data, true)); // Log pour afficher les données de la transaction

    if (is_null($data)) {
        \Log::info('Invalid Deposit Request - No data found');
        return redirect()->route('user.dashboard')->with('alert', 'Invalid Deposit Request');
    }

    if ($data->status != 0) {
        \Log::info('Invalid Deposit Request - Status is not 0');
        return redirect()->route('user.dashboard')->with('alert', 'Invalid Deposit Request');
    }

    // Vérifier les informations de la passerelle associée à ce dépôt
    $gatewayData = Gateway::where('id', $data->gateway_id)->first();
    \Log::info('Gateway Data: ' . print_r($gatewayData, true)); // Log pour afficher les données de la passerelle

    // Si c'est une passerelle fictive ou PayPal, traiter le paiement manuellement
    if ($data->gateway_id == 101) {  // Supposons que 101 est un ID valide pour PayPal ou un test
        \Log::info('Manual Deposit Confirmed for Transaction: ' . $data->trx);
        
        // Appel à la fonction qui met à jour les données utilisateur et le dépôt
        $this->userDataUpdate($data);
        
        // Rediriger vers le tableau de bord avec un message de succès
        return redirect()->route('user.dashboard')->with('success', 'Deposit successfully processed.');
    }
    
    // Si ce n'est pas une passerelle fictive, il faudrait traiter d'autres types de passerelles ici
}


    //IPN Functions //////     
        
    public function ipnpaypal()
    {
        $raw_post_data = file_get_contents('php://input');
        $raw_post_array = explode('&', $raw_post_data);
        $myPost = array();
        foreach ($raw_post_array as $keyval)
        {
            $keyval = explode ('=', $keyval);
            if (count($keyval) == 2)
                $myPost[$keyval[0]] = urldecode($keyval[1]);
        }
                        
        $req = 'cmd=_notify-validate';
        if(function_exists('get_magic_quotes_gpc'))
        {
            $get_magic_quotes_exists = true;
        }
        foreach ($myPost as $key => $value)
        {
            if($get_magic_quotes_exists == true && get_magic_quotes_gpc() == 1) 
            {
                $value = urlencode(stripslashes($value));
            } else 
            {
                $value = urlencode($value);
            }
            $req .= "&$key=$value";
        }
    
        $paypalURL = "https://ipnpb.paypal.com/cgi-bin/webscr?";
        $callUrl = $paypalURL.$req;
        $verify = file_get_contents($callUrl);
    
        // Vérification si la réponse est "VERIFIED"
        if($verify=="VERIFIED"){
            // Si PayPal a validé le paiement
            $receiver_email = $_POST['receiver_email'];
            $mc_currency = $_POST['mc_currency'];
            $mc_gross = $_POST['mc_gross'];
            $track = $_POST['custom'];
    
            // Récupérer les données du dépôt correspondant au Track ID
            $data = Deposit::where('trx', $track)->orderBy('id', 'DESC')->first();
            $gatewayData = Gateway::find(101);  // ID passerelle PayPal
            $amount = $data->usd_amo;
            
            // Vérifier si les conditions sont valides (email, montant, statut)
            if($receiver_email == $gatewayData->val1 && $mc_currency == "USD" && $mc_gross == $amount && $data->status == '0') {
                \Log::info('PayPal IPN VERIFIED - Proceeding with deposit update for transaction: ' . $data->trx);
                
                // Mettre à jour les données de l'utilisateur et le dépôt
                $this->userDataUpdate($data); 
            }
        }
    }
    
    public function ipnperfect()
    {
        
        $gatewayData = Gateway::find(102);
        $passphrase = strtoupper(md5($gatewayData->val2));
        
        define('ALTERNATE_PHRASE_HASH', $passphrase);
        define('PATH_TO_LOG', '/somewhere/out/of/document_root/');
        $string =
        $_POST['PAYMENT_ID'] . ':' . $_POST['PAYEE_ACCOUNT'] . ':' .
        $_POST['PAYMENT_AMOUNT'] . ':' . $_POST['PAYMENT_UNITS'] . ':' .
        $_POST['PAYMENT_BATCH_NUM'] . ':' .
        $_POST['PAYER_ACCOUNT'] . ':' . ALTERNATE_PHRASE_HASH . ':' .
        $_POST['TIMESTAMPGMT'];
        
        $hash = strtoupper(md5($string));
        $hash2 = $_POST['V2_HASH'];

        if ($hash == $hash2) 
        {
            $amo = $_POST['PAYMENT_AMOUNT'];
            $unit = $_POST['PAYMENT_UNITS'];
            $track = $_POST['PAYMENT_ID'];
            
            $data = Deposit::where('trx', $track)->orderBy('id', 'DESC')->first();
            $gnl = Setting::first();
            
            if ($_POST['PAYEE_ACCOUNT'] == $gatewayData->val1 && $unit == "USD" && $amo == $data->usd_amo && $data->status == '0')
            {
                //Update User Data
                $this->userDataUpdate($data);               
            }
        }
            
    }
                
    public function ipnstripe(Request $request)
    {
        $track = Session::get('Track');
        $data = Deposit::where('trx', $track)->orderBy('id', 'DESC')->first();
        
        $this->validate($request,
        [
            'cardNumber' => 'required',
            'cardExpiry' => 'required',
            'cardCVC' => 'required',
        ]);
            
        $cc = $request->cardNumber;
        $exp = $request->cardExpiry;
        $cvc = $request->cardCVC;
        
        $exp = $pieces = explode("/", $_POST['cardExpiry']);
        $emo = trim($exp[0]);
        $eyr = trim($exp[1]);
        $cnts = round($data->usd_amo,2) * 100;
            
        $gatewayData = Gateway::find(103);
        $gnl = Setting::first();
        
        Stripe::setApiKey($gatewayData->val1);
        
        try 
        {
            $token = Token::create(array(
                "card" => array(
                    "number" => "$cc",
                    "exp_month" => $emo,
                    "exp_year" => $eyr,
                    "cvc" => "$cvc"
                    )
                ));
            
            try 
            {
                $charge = Charge::create(array(
                    'card' => $token['id'],
                    'currency' => 'USD',
                    'amount' => $cnts,
                    'description' => 'item',
                ));
            
                if ($charge['status'] == 'succeeded') {
                    
                    //Update User Data
                    $this->userDataUpdate($data);
                    return redirect()->route('user.dashboard')->with('success', 'Deposit Successful');
                        
                }
                    
            } 
            catch (Exception $e) 
            {
                return redirect()->route('user.dashboard')->with('alert', $e->getMessage());
            }
                
        } 
        catch (Exception $e) 
        {
            return redirect()->route('user.dashboard')->with('alert', $e->getMessage());
        }
                    
    }

    public function skrillIPN()
    {
        $skrill = Gateway::find(104);
        $concatFields = $_POST['merchant_id']
        . $_POST['transaction_id']
        . strtoupper(md5($skrill->val2))
        . $_POST['mb_amount']
        . $_POST['mb_currency']
        . $_POST['status'];
        
        $data = Deposit::where('trx',$track)->orderBy('id', 'DESC')->first();
        $gnl = Setting::first();
        
        if(strtoupper(md5($concatFields)) == $_POST['md5sig'] && $_POST['status'] == 2 && $_POST['pay_to_email'] == $skrill->val1 && $data->status = '0') 
        {
            //Update User Data
            $this->userDataUpdate($data); 
           
        }
    }

    public function ipnPayTm(Request $request)
    {
        $gateway = Gateway::find(105);

        $paytm_merchant_key = $gateway->val2;
        $paytm_merchant_id = $gateway->val1;
        $transaction_status_url = $gateway->val7;

        if(verifychecksum_e($_POST, $paytm_merchant_key, $_POST['CHECKSUMHASH']) === "TRUE") {

            if($_POST['RESPCODE'] == "01"){
                // Create an array having all required parameters for status query.
                $requestParamList = array("MID" => $paytm_merchant_id, "ORDERID" => $_POST['ORDERID']);
                // $_POST['ORDERID'] = substr($_POST['ORDERID'], strpos($_POST['ORDERID'], "-") + 1); // just for testing
                $StatusCheckSum = getChecksumFromArray($requestParamList, $paytm_merchant_key);
                $requestParamList['CHECKSUMHASH'] = $StatusCheckSum;
                $responseParamList = callNewAPI($transaction_status_url, $requestParamList);
                if($responseParamList['STATUS'] == 'TXN_SUCCESS' && $responseParamList['TXNAMOUNT'] == $_POST['TXNAMOUNT']) {
                    $ddd = Deposit::where('trx',$_POST['ORDERID'])->orderBy('id', 'DESC')->first();
                    $this->userDataUpdate($ddd);
                    $t = 'success';
                    $m = 'Transaction has been successful';
                } else  {
                    $t = 'alert';
                    $m = 'It seems some issue in server to server communication. Kindly connect with administrator';
                }
            } else {
                $t = 'alert';
                $m = $_POST['RESPMSG'];
            }
        } else {
            $t = 'alert';
            $m = "Security error!";
        }
        return redirect()->route('home')->with($t, $m);
    }

    public function ipnPayEer(Request $request)
    {

        if (isset($_GET['payeer']) && $_GET['payeer'] == 'result')
        {
            if (isset($_POST["m_operation_id"]) && isset($_POST["m_sign"]))
            {
                $err = false;
                $message = '';

                $gateway = Gateway::find(106);

                $sign_hash = strtoupper(hash('sha256', implode(":", array(
                    $_POST['m_operation_id'],
                    $_POST['m_operation_ps'],
                    $_POST['m_operation_date'],
                    $_POST['m_operation_pay_date'],
                    $_POST['m_shop'],
                    $_POST['m_orderid'],
                    $_POST['m_amount'],
                    $_POST['m_curr'],
                    $_POST['m_desc'],
                    $_POST['m_status'],
                    $gateway->val2
                ))));

                if ($_POST["m_sign"] != $sign_hash)
                {
                    $message .= " - do not match the digital signature\n";
                    $err = true;
                }

                if (!$err)
                {

                    $ddd = Deposit::find($_POST['m_orderid']);

                    $order_curr = 'USD';
                    $order_amount = round($ddd->usd_amo, 2);

                    if ($_POST['m_amount'] != $order_amount)
                    {
                        $message .= " - wrong amount\n";
                        $err = true;
                    }

                    if ($_POST['m_curr'] != $order_curr)
                    {
                        $message .= " - wrong currency\n";
                        $err = true;
                    }

                    if (!$err)
                    {
                        switch ($_POST['m_status'])
                        {
                            case 'success':

                                $this->userDataUpdate($ddd);
                                $message = 'Sell Successfully Completed';
                                $err = false;

                                break;

                            default:
                                $message .= " - the payment status is not success\n";
                                $err = true;
                                break;
                        }
                    }
                }

                if ($err)
                {
                    return redirect()->route('home')->with('success', $message);
                }
                else
                {
                    return redirect()->route('home')->with('success', $message);
                }
            }
        }

    }

    public function purchaseVogue($trx, $type)
    {

        if ($type == 'error') redirect()->route('home')->with('alert', 'Transaction Failed, Ref: ' . $trx);
        return redirect()->route('home')->with('success', 'Transaction was successful, Ref: ' . $trx);

    }

    public function ipnPayStack(Request $request)
    {

        $request->validate([
            'reference' => 'required',
            'paystack-trxref' => 'required',
        ]);

        $gateway = Gateway::find(107);

        $ref = $request->reference;
        $secret_key = $gateway->val2;

        $result = array();
        //The parameter after verify/ is the transaction reference to be verified
        $url = 'https://api.paystack.co/transaction/verify/' . $ref;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $secret_key]);
        $r = curl_exec($ch);
        curl_close($ch);

        if ($r) {
            $result = json_decode($r, true);

            if($result){
                if($result['data']){
                    if ($result['data']['status'] == 'success') {
                        $ddd = Deposit::where('trx', $ref)->first();
                        $am = $result['data']['amount'];
                        $sam = round($ddd->usd_amo/$ddd->gateway->val7, 2)*100;
                        if ($am == $sam) {
                            $this->userDataUpdate($ddd);
                            return redirect()->route('home')->with('success', 'Sell Successful');
                        } else {
                            return redirect()->route('home')->with('alert', 'Less Amount Paid. Please Contact With Admin');
                        }
                    }else{
                        return redirect()->route('home')->with('alert', $result['data']['gateway_response']);
                    }
                }else{
                    return redirect()->route('home')->with('alert', $result['message']);
                }

            }else{
                return redirect()->route('home')->with('alert', 'Something went wrong while executing');
            }
        }else{
            return redirect()->route('home')->with('alert', 'Something went wrong while executing');
        }

    }

    public function ipnVoguePay(Request $request)
    {

        $request->validate([
            'transaction_id' => 'required'
        ]);

        $trx = $request->transaction_id;

        $req_url = "https://voguepay.com/?v_transaction_id=$trx&type=json";
        $data = file_get_contents($req_url);
        $data = json_decode($data);

        $merchant_id = $data->merchant_id;
        $total_paid = $data->total;
        $custom = $data->merchant_ref;
        $status = $data->status;
        $vogue = Gateway::find(108);

        if($status == "Approved" && $merchant_id == $vogue->val1){

            $ddd = Deposit::where('trx' , $custom)->first();
            $totalamo = $ddd->usd_amo;

            if($totalamo == $total_paid)
            {
                $this->userDataUpdate($ddd);
            }
        }

    }
    
    public function ipnBchain()
    {

        $gatewayData = Gateway::find(501);
        $track = $_GET['invoice_id'];
        $secret = $_GET['secret'];
        $address = $_GET['address'];
        $value = $_GET['value'];
        $confirmations = $_GET['confirmations'];
        $value_in_btc = $_GET['value'] / 100000000;
    
        $trx_hash = $_GET['transaction_hash'];
    
        $data = Deposit::where('trx',$track)->orderBy('id', 'DESC')->first();
    
    
        if ($data->status==0) 
        {
            if($data->btc_amo==$value_in_btc && $data->btc_wallet==$address && $secret=="ABIR" && $confirmations>2)
            {
               //Update User Data
               $this->userDataUpdate($data);
    
            }
    
        }
    
    }
   
    public function blockIpnBtc(Request $request)
    {
        $DepositData = Deposit::where('status', 0)->where('gateway_id', 502)->where('try','<=',100)->get();

        $method = Gateway::find(502);
        $apiKey = $method->val1;
        $version = 2; 
        $pin =  $method->val2;
        $block_io = new BlockIo($apiKey, $pin, $version);

        foreach($DepositData as $data)
        {
            $balance = $block_io->get_address_balance(array('addresses' => $data->btc_wallet));
            $bal = $balance->data->available_balance;

            if($bal > 0 && $bal >= $data->btc_amo)
            {
               //Update User Data
               $this->userDataUpdate($data);
            }	
            $data['try'] = $data->try + 1;
            $data->update();
        }
    }

    public function blockIpnLite(Request $request)
    {

        $DepositData = Deposit::where('status', 0)->where('gateway_id', 503)->where('try','<=',100)->get();

        $method = Gateway::find(503);
        $apiKey = $method->val1;
        $version = 2; 
        $pin =  $method->val2;
        $block_io = new BlockIo($apiKey, $pin, $version);


        foreach($DepositData as $data)
        {
            $balance = $block_io->get_address_balance(array('addresses' => $data->btc_wallet));
            $bal = $balance->data->available_balance;

            if($bal > 0 && $bal >= $data->btc_amo)
            {
               //Update User Data
               $this->userDataUpdate($data);
            }	
            $data['try'] = $data->try + 1;
            $data->update();
        }
    }
    public function blockIpnDog(Request $request)
    {
        $DepositData = Deposit::where('status', 0)->where('gateway_id', 504)->where('try','<=',100)->get();

        $method = Gateway::find(504);
        $apiKey = $method->val1;
        $version = 2; 
        $pin =  $method->val2;
        $block_io = new BlockIo($apiKey, $pin, $version);


        foreach($DepositData as $data)
        {
            $balance = $block_io->get_address_balance(array('addresses' => $data->btc_wallet));
            $bal = $balance->data->available_balance;

            if($bal > 0 && $bal >= $data->btc_amo)
            {
               //Update User Data
               $this->userDataUpdate($data);
            }	
            $data['try'] = $data->try + 1;
            $data->update();
        }
    }

    public function ipnCoinPayBtc(Request $request)
    {
        $track = $request->custom;
        $status = $request->status;
        $amount2 = floatval($request->amount2);
        $currency2 = $request->currency2;

        $data = Deposit::where('trx',$track)->orderBy('id', 'DESC')->first();
        $bcoin = $data->btc_amo;
        if ($status>=100 || $status==2) 
        {
            if ($currency2 == "BTC" && $data->status == '0' && $data->btc_amo<=$amount2) 
            {
                $this->userDataUpdate($data);
            }
        }
    }
    
    public function ipnCoinPayEth(Request $request)
    {
        $track = $request->custom;
        $status = $request->status;
        $amount2 = floatval($request->amount2);
        $currency2 = $request->currency2;

        $data = Deposit::where('trx',$track)->orderBy('id', 'DESC')->first();
        $bcoin = $data->btc_amo;
        if ($status>=100 || $status==2) 
        {
            if ($currency2 == "ETH" && $data->status == '0' && $data->btc_amo<=$amount2) 
            {
                $this->userDataUpdate($data);
            }
        }
    }
    public function ipnCoinPayBch(Request $request)
    {
        $track = $request->custom;
        $status = $request->status;
        $amount2 = floatval($request->amount2);
        $currency2 = $request->currency2;

        $data = Deposit::where('trx',$track)->orderBy('id', 'DESC')->first();
        $bcoin = $data->btc_amo;
        if ($status>=100 || $status==2) 
        {
            if ($currency2 == "BCH" && $data->status == '0' && $data->btc_amo<=$amount2) 
            {
                $this->userDataUpdate($data);
            }
        }
    }
    public function ipnCoinPayDash(Request $request)
    {
        $track = $request->custom;
        $status = $request->status;
        $amount2 = floatval($request->amount2);
        $currency2 = $request->currency2;

        $data = Deposit::where('trx',$track)->orderBy('id', 'DESC')->first();
        $bcoin = $data->btc_amo;
        if ($status>=100 || $status==2) 
        {
            if ($currency2 == "DASH" && $data->status == '0' && $data->btc_amo<=$amount2) 
            {
                $this->userDataUpdate($data);
            }
        }
    }
    public function ipnCoinPayDoge(Request $request)
    {
        $track = $request->custom;
        $status = $request->status;
        $amount2 = floatval($request->amount2);
        $currency2 = $request->currency2;

        $data = Deposit::where('trx',$track)->orderBy('id', 'DESC')->first();
        $bcoin = $data->btc_amo;
        if ($status>=100 || $status==2) 
        {
            if ($currency2 == "DOGE" && $data->status == '0' && $data->btc_amo<=$amount2) 
            {
                $this->userDataUpdate($data);
            }
        }
    }
    public function ipnCoinPayLtc(Request $request)
    {
        $track = $request->custom;
        $status = $request->status;
        $amount2 = floatval($request->amount2);
        $currency2 = $request->currency2;

        $data = Deposit::where('trx',$track)->orderBy('id', 'DESC')->first();
        $bcoin = $data->btc_amo;
        if ($status>=100 || $status==2) 
        {
            if ($currency2 == "LTC" && $data->status == '0' && $data->btc_amo<=$amount2) 
            {
                $this->userDataUpdate($data);
            }
        }
    }

    public function ipnCoinGate() 
    {
        $data = Deposit::where('trx',$_POST['order_id'])->orderBy('id', 'DESC')->first();

        if($_POST['status'] == 'paid' && $_POST['price_amount'] == $data->usd_amo && $data->status == '0') 
        {
			$this->userDataUpdate($data);
		}

	}

    public function ipnCoin(Request $request)
    {
        $track = $request->custom;
        $status = $request->status;
        $amount1 = floatval($request->amount1);
        $currency1 = $request->currency1;

        $data = Deposit::where('trx', $track)->orderBy('id','DESC')->first();
        $bcoin = $data->btc_amo;

        if ($currency1 == "BTC" && $amount1 >= $bcoin && $data->status == '0') 
        {
            if ($status>=100 || $status==2) 
            {
                //Update User Data
               $this->userDataUpdate($data);
            }
        }

    }

    
}	
                                        