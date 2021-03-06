<?php

namespace frontend\modules\lab\controllers;

use Yii;
use frontend\modules\lab\components\eRequest;
use common\models\lab\exRequest;
use common\models\lab\exRequestreferral;
use common\models\lab\ReferralRequest;
use common\models\lab\Request;
use common\models\lab\Discount;
use common\models\lab\Analysis;
use common\models\lab\RequestSearch;
use common\models\lab\Requestcode;
use yii\web\Controller;
use yii\web\NotFoundHttpException;
use yii\filters\VerbFilter;
use yii\data\ActiveDataProvider;
use common\models\lab\Sample;
use yii\db\Query;
use common\models\lab\Customer;
use DateTime;
use common\models\system\Profile;
use common\components\Functions;
use common\components\ReferralComponent;
use kartik\mpdf\Pdf;
use frontend\modules\finance\components\epayment\ePayment;
use common\models\finance\Op;
use common\models\system\Rstl;
use linslin\yii2\curl;
use codemix\excelexport\ExcelFile;
use common\models\system\User;
use frontend\modules\lab\components\Printing;
use yii\helpers\Json;
use yii\helpers\ArrayHelper;

//use yii\helpers\Url;
/**
 * RequestController implements the CRUD actions for Request model.
 */
class RequestController extends Controller
{
    /**
     * @inheritdoc
     */
    public function behaviors()
    {
        return [
            'verbs' => [
                'class' => VerbFilter::className(),
                'actions' => [
                    'delete' => ['POST']
                ],
            ],
        ];
    }

    /**
     * Lists all Request models.
     * @return mixed
     */
    public function actionIndex()
    { 
        $Func=new Functions();
        $Func->CheckRSTLProfile();
        
        $searchModel = new RequestSearch();
        $dataProvider = $searchModel->search(Yii::$app->request->queryParams);
        $dataProvider->pagination->pageSize=6;
        $GLOBALS['rstl_id']=Yii::$app->user->identity->profile->rstl_id;
        return $this->render('index', [
            'searchModel' => $searchModel,
            'dataProvider' => $dataProvider,
        ]);
    }
    public function actionPrintRequest($id){
        $Printing=new Printing();
        $Printing->PrintRequest($id);
    }
    /**
     * Displays a single Request model.
     * @param integer $id
     * @return mixed
     */
    public function actionView($id)
    {
        $searchModel = new RequestSearch();
        $dataProvider = $searchModel->search(Yii::$app->request->queryParams);
        $dataProvider->pagination->pageSize=10;
        $samplesQuery = Sample::find()->where(['request_id' => $id]);
        $sampleDataProvider = new ActiveDataProvider([
            'query' => $samplesQuery,
            'pagination' => [
                'pageSize' => 10,
            ],
        ]);

        $request_type = $this->findModel($id)->request_type_id;
        $GLOBALS['rstl_id']=Yii::$app->user->identity->profile->rstl_id;     
        $samples = Sample::find()->where(['request_id' => $id])->all();
        
        $sample_ids = '';
        foreach ($samples as $sample){
            $sample_ids .= $sample->sample_id.",";
        }
        $sample_ids = substr($sample_ids, 0, strlen($sample_ids)-1);
       
        if ($sample_ids){
            $ids = explode(",", $sample_ids);   
        }else{
            $ids = ['-1'];
        }
        
        $analysisQuery = Analysis::find()
        ->where(['IN', 'sample_id', $ids]);

        if($request_type == 2) {
            $analysisdataprovider = new ActiveDataProvider([
                'query' => $analysisQuery,
                'pagination' => [
                    'pageSize' => 10,
                ]
            ]);
        } else {
            $analysisdataprovider = new ActiveDataProvider([
                'query' => $analysisQuery,
                'pagination' =>false,
            ]);
        }
        
        if(\Yii::$app->user->can('view-all-rstl')){
            $model=exRequest::findOne($id);
        }else{
            $model=$this->findRequestModel($id);
        }

        $connection= Yii::$app->labdb;
        $fee = $connection->createCommand('SELECT SUM(fee) as subtotal FROM tbl_analysis WHERE request_id =:requestId')
        ->bindValue(':requestId',$id)->queryOne();
        $subtotal = $fee['subtotal'];
        $rate = $model->discount;
        $discounted = $subtotal * ($rate/100);
        $total = $subtotal - $discounted;

        if($request_type == 2){
            return $this->render('viewreferral', [
                'model' => $model,
                'searchModel' => $searchModel,
                'dataProvider' => $dataProvider,
                'sampleDataProvider' => $sampleDataProvider,
                'analysisdataprovider'=> $analysisdataprovider,
            ]);

        } else {
            return $this->render('view', [
                'model' => $model,
                'searchModel' => $searchModel,
                'dataProvider' => $dataProvider,
                'sampleDataProvider' => $sampleDataProvider,
                'analysisdataprovider'=> $analysisdataprovider,
            ]);
        }
    }
    public function actionCustomerlist($q = null, $id = null) {
        \Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;
        $out = ['results' => ['id' => '', 'text' => '']];
        if (!is_null($q)) {
            $query = new Query;
            $query->select('customer_id as id, customer_name AS text')
                    ->from('tbl_customer')
                    ->where(['like', 'customer_name', $q])
                    ->limit(20);
            $command = $query->createCommand();
            $command->db= \Yii::$app->labdb;
            $data = $command->queryAll();
            $out['results'] = array_values($data);
        } elseif ($id > 0) {
            $out['results'] = ['id' => $id, 'text' =>Customer::find()->where(['customer_id'=>$id])->customer_name];
        }
        return $out;
    }
    public function actionPdf(){
        $pdf=new \common\components\MyPDF();
        $Content="<button>Click me</button>";

        $pdf->renderPDF($Content,NULL,NULL,['orientation'=> Pdf::ORIENT_LANDSCAPE]);
    }

    public function actionPrintlabel(){

       if(isset($_GET['request_id'])){
        $id = $_GET['request_id'];
        $mpdf = new \Mpdf\Mpdf([
            'format' => [35,66], 
            'orientation' => 'L',
        ]);
        $request = Request::find()->where(['request_id' => $id]);
        $samplesquery = Sample::find()->where(['request_id' => $id])->all();
        $requestquery = Request::find()->where(['request_id' => $id])->one();
        foreach ($samplesquery as $sample) {
            $limitreceived_date = substr($requestquery['request_datetime'], 0,10);
            $mpdf->AddPage('','','','','',0,0,0,0);
            $samplecode = '<font size="2">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<b>'.$sample['sample_code']."</b>&nbsp;&nbsp;".$sample['samplename'].
            '<br>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<font size="1"><b>Received:&nbsp;&nbsp;</b>'.$limitreceived_date.'&nbsp;&nbsp;<b>Due:&nbsp;&nbsp;</b>'.$requestquery['report_due'];
        
            $mpdf->WriteHTML("<barcode code=".$sample['sample_code']." type='C39' />");
            $mpdf->WriteHTML($samplecode);

            $text = '<font size="5">WI-003-F1';
            $text2 = '<font size="5"><b>Rev 03/03.01.18<b>';

            $i = 1;
            $analysisquery = Analysis::find()->where(['sample_id' => $sample['sample_id']])->all();
                   foreach ($analysisquery as $analysis){
                        $mpdf->WriteHTML("&nbsp;&nbsp;&nbsp;&nbsp;<font size='2'>".$analysis['testname']."</font>");

                        if ($i++ == 3)
                        break;
                   }               
            }          
            $mpdf->Output();
       }
    }
    public function actionTestpayment(){
        //$json=Yii::$app->getRequest()->getBodyParams();
        //return $json;
            $Op= Op::findOne(14);
            $Op->payment_mode_id=5;//Online Payment
            $Op->save(false);
    }
    public function actionTest($id){
        //$ePayment=new ePayment();
        //$result=$ePayment->PostOnlinePayment($id);
        //Yii::$app->response->format= \yii\web\Response::FORMAT_JSON;
        //return $result;
        $Func=new Functions();
        $Proc="spGetPaymentForOnline(:op_id)";
        $Connection= \Yii::$app->financedb;
        $param=[
            'op_id'=>$id
        ];
        $requests=$Func->ExecuteStoredProcedureRows($Proc, $param, $Connection);
        $Payment_details=[];
        foreach($requests as $request){
            $payment_detail=[
                'request_ref_num'=>$request['request_ref_num'],
                'rrn_date_time'=>$request['request_datetime'],
                'amount'=>$request['amount']
            ];
            array_push($Payment_details, $payment_detail);
        }
        //Query order of payment
        $Op= Op::findOne($id);
        $Rstl= Rstl::findOne($Op->rstl_id);
        $Customer= Customer::findOne($Op->customer_id);
        $TransactDetails=[
            'transaction_num'=>$Op->transactionnum,
            'customer_code'=>$Customer->customer_code,
            'collection_type'=>$Op->collectiontype->natureofcollection,
            'collection_code'=>'collection-code',
            'order_date'=>$Op->order_date,
            'agency_code'=>$Rstl->code,
            'total_amount'=>$Op->total_amount,
            'payment_details'=>$Payment_details
        ];
        $content = json_encode($TransactDetails);
    
        $curl = new curl\Curl();
        $EpaymentURI="https://yii2customer.onelab.ph/web/api/op";
    
        $response = $curl->setRequestBody($content)
            ->setHeaders([
               'Content-Type' => 'application/json',
               'Content-Length' => strlen($content)
            ])->post($EpaymentURI);
        $result=json_decode($response);
        return $result->description;
    }
    public function actionSaverequestransaction(){
        $post= Yii::$app->request->post();
       
        $return="Failed";
        $request_id=(int) $post['request_id'];
        $lab_id=(int) $post['lab_id'];
        $rstl_id=(int) $post['rstl_id'];
        $year=(int) $post['year'];
        // Generate Reference Number
        $func=new Functions();
        $Proc="spGetNextGeneratedRequestCode(:RSTLID,:LabID)";
        $Params=[
            ':RSTLID'=>$rstl_id,
            ':LabID'=>$lab_id
        ];
        $Connection= Yii::$app->labdb;
        $Transaction =$Connection->beginTransaction();
        $Row=$func->ExecuteStoredProcedureOne($Proc, $Params, $Connection);
        $ReferenceNumber=$Row['GeneratedRequestCode'];
        $RequestIncrement=$Row['RequestIncrement'];
        //Update the tbl_requestcode
        $Requestcode= Requestcode::find()->where([
            'rstl_id'=>$rstl_id,
            'lab_id'=>$lab_id,
            'year'=>$year
        ])->one($Connection);
        
        if(!$Requestcode){
            $Requestcode=new Requestcode();
        }
        $Requestcode->request_ref_num=$ReferenceNumber;
        $Requestcode->rstl_id=$rstl_id;
        $Requestcode->lab_id=$lab_id;
        $Requestcode->number=$RequestIncrement;
        $Requestcode->year=$year;
        $Requestcode->cancelled=0;
        $Requestcode->save();
        //Update tbl_request table
        $Request= Request::find()->where(['request_id'=>$request_id])->one($Connection);
        $Request->request_ref_num=$ReferenceNumber;
       
        $discountquery = Discount::find()->where(['discount_id' => $Request->discount_id])->one();

        $rate =  $discountquery->rate;
        
        $sql = "SELECT SUM(fee) as subtotal FROM tbl_analysis WHERE request_id=$request_id";
        $command = $Connection->createCommand($sql);
        $row = $command->queryOne();
        $subtotal = $row['subtotal'];
        $total = $subtotal - ($subtotal * ($rate/100));
        
        $Request->total=$total;
      
        if($Request->save()){
            $Func=new Functions();
            $response=$Func->GenerateSampleCode($request_id);
            if($response){
                $return="Success";
                Yii::$app->session->setFlash('success', 'Request Reference # and Sample Code Successfully Generated!');
                $Transaction->commit();
            }else{
                $Transaction->rollback();
                Yii::$app->session->setFlash('danger', 'Request Reference # and Sample Code Failed to Generate!');
                $return="Failed";
            }
        }else{
            Yii::$app->session->setFlash('danger', 'Request Reference # and Sample Code Failed to Generate!');
            $Transaction->rollback();
            $return="Failed";
        }
        return $return;
    }
    /**
     * Creates a new Request model.
     * If creation is successful, the browser will be redirected to the 'view' page.
     * @return mixed
     */
    public function actionCreate()
    {
        $model = new eRequest();
        $Func=new Functions();
        $Func->CheckRSTLProfile();
        $GLOBALS['rstl_id']=Yii::$app->user->identity->profile->rstl_id;
        /*echo "<pre>";
        print_r(Yii::$app->request->post());
        echo "</pre>";
        exit;
         * 
         */
        if ($model->load(Yii::$app->request->post()) && $model->save()) {
            Yii::$app->session->setFlash('success', 'Request Successfully Created!');
            return $this->redirect(['view', 'id' => $model->request_id]); 
        } else {
            $date = new DateTime();
            $date2 = new DateTime();
            $profile= Profile::find()->where(['user_id'=> Yii::$app->user->id])->one();
            date_add($date2,date_interval_create_from_date_string("1 day"));
            $model->request_datetime=date("Y-m-d h:i:s");
            $model->report_due=date_format($date2,"Y-m-d");
            $model->created_at=date('U');
            $model->rstl_id= Yii::$app->user->identity->profile->rstl_id;
            $model->payment_type_id=1;
            $model->modeofrelease_ids='1';
            $model->discount_id=0;
            $model->discount='0.00';
            $model->total=0.00;
            $model->posted=0;
            $model->status_id=1;
            $model->request_type_id=0;
            $model->modeofreleaseids='1';
            $model->payment_status_id=1;
            $model->request_date=date("Y-m-d");
            if($profile){
                $model->receivedBy=$profile->firstname.' '. strtoupper(substr($profile->middleinitial,0,1)).'. '.$profile->lastname;
            }else{
                $model->receivedBy="";
            }
            if(\Yii::$app->request->isAjax){
                return $this->renderAjax('create', [
                    'model' => $model,
                ]);
            }else{
                return $this->renderAjax('create', [
                    'model' => $model,
                ]);
            }
        }
    }

    /**
     * Updates an existing Request model.
     * If update is successful, the browser will be redirected to the 'view' page.
     * @param integer $id
     * @return mixed
     */
    public function actionUpdate($id)
    {
        $model= eRequest::findOne($id);
        
        if ($model->load(Yii::$app->request->post()) && $model->save()) {
            Yii::$app->session->setFlash('success', 'Request Successfully Updated!');
            return $this->redirect(['view', 'id' => $model->request_id]);
        } else {
            if($model->request_ref_num){
                $model->request_ref_num=NULL;
            }
            if(\Yii::$app->request->isAjax){
                return $this->renderAjax('update', [
                    'model' => $model,
                ]);
            }else{
                return $this->render('update', [
                    'model' => $model,
                ]);
            }
        }
    }

    /**
     * Deletes an existing Request model.
     * If deletion is successful, the browser will be redirected to the 'index' page.
     * @param integer $id
     * @return mixed
     */
    public function actionDelete($id)
    {
        $Request=$this->findModel($id);
        if($Request){//Success
            $Request->status_id=2;
            $ret=$Request->save();
        }else{
            $ret=false;
        }
        return $ret;
    }

    /**
     * Finds the Request model based on its primary key value.
     * If the model is not found, a 404 HTTP exception will be thrown.
     * @param integer $id
     * @return Request the loaded model
     * @throws NotFoundHttpException if the model cannot be found
     */
    protected function findModel($id)
    {
        if (($model = Request::findOne($id)) !== null) {
            return $model;
        } else {
            throw new NotFoundHttpException('The requested page does not exist.');
        }
    }
     protected function findRequestModel($id)
    {
        $rstl_id=Yii::$app->user->identity->profile ? Yii::$app->user->identity->profile->rstl_id : -1;
        $model=exRequest::find()->where(['request_id'=>$id,'rstl_id'=>$rstl_id])->one();
        if ($model!== null) {
            return $model;
        } else {
            throw new NotFoundHttpException('The requested Request its either does not exist or you have no permission to view it.');
        }
    }
  
    //contacted by function to return result to be displayed in select2
    public function actionRequestlist($q = null, $id = null) {
        \Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;
        $out = ['results' => ['id' => '', 'text' => '']];
        if (!is_null($q)) {
            $query = new Query;
            $query->select('request_id as id, request_ref_num AS text')
                    ->from('tbl_request')
                    ->where(['like', 'request_ref_num', $q])
                    ->limit(20);
            $command = $query->createCommand();
            $command->db= \Yii::$app->labdb;
            $data = $command->queryAll();
            $out['results'] = array_values($data);
        } elseif ($id > 0) {
            $out['results'] = ['id' => $id, 'text' =>Request::find()->where(['request_id'=>$id])->request_ref_num];
        }
        return $out;
    }

    /**
     * Creates a new Request model.
     * If creation is successful, the browser will be redirected to the 'view' page.
     * @return mixed
     */
    public function actionCreatereferral()
    {
        $model = new exRequestreferral();
        $Func=new Functions();
        $refcomponent = new ReferralComponent();
        $Func->CheckRSTLProfile();
        $connection= Yii::$app->labdb;
        //$GLOBALS['rstl_id']=Yii::$app->user->identity->profile->rstl_id;
        $labreferral = ArrayHelper::map(json_decode($refcomponent->listLabreferral()), 'lab_id', 'labname');
        $discountreferral = ArrayHelper::map(json_decode($refcomponent->listDiscountreferral()), 'discount_id', 'type');
        $purposereferral = ArrayHelper::map(json_decode($refcomponent->listPurposereferral()), 'purpose_id', 'name');
        $modereleasereferral = ArrayHelper::map(json_decode($refcomponent->listModereleasereferral()), 'modeofrelease_id', 'mode');
        
        if ($model->load(Yii::$app->request->post())) {
            $transaction = $connection->beginTransaction();
            $modelReferralrequest = new ReferralRequest();
            if ($model->save()){
                $modelReferralrequest->request_id = $model->request_id;
                $modelReferralrequest->sample_receive_date = date('Y-m-d h:i:s',strtotime($model->sample_receive_date));
                $modelReferralrequest->receiving_agency_id = Yii::$app->user->identity->profile->rstl_id;
                //$modelReferralrequest->testing_agency_id = null;
                $modelReferralrequest->referral_type_id = 1;
                if ($modelReferralrequest->save()){
                    $transaction->commit();
                } else {
                    $transaction->rollBack();
                    $modelReferralrequest->getErrors();
                    return false;
                }
                Yii::$app->session->setFlash('success', 'Referral Request Successfully Created!');
                return $this->redirect(['view', 'id' => $model->request_id]); ///lab/request/view?id=1
            } else {
                $transaction->rollBack();
                $model->getErrors();
                return false;
            }
        } else {
            $date = new DateTime();
            $date2 = new DateTime();
            $profile= Profile::find()->where(['user_id'=> Yii::$app->user->id])->one();
            date_add($date2,date_interval_create_from_date_string("1 day"));
            //$model->request_datetime=date("Y-m-d h:i:s");
            $model->request_datetime="0000-00-00 00:00:00";
            $model->report_due=date_format($date2,"Y-m-d");
            $model->created_at=date('U');
            $model->rstl_id=Yii::$app->user->identity->profile->rstl_id;//$GLOBALS['rstl_id'];
            $model->payment_type_id=1;
            $model->modeofrelease_ids='1';
            $model->discount_id=0;
            $model->discount='0.00';
            $model->total=0.00;
            $model->posted=0;
            $model->status_id=1;
            $model->request_type_id=2;
            $model->modeofreleaseids='1';
            $model->payment_status_id=1;
            $model->request_date=date("Y-m-d");
            if($profile){
                $model->receivedBy=$profile->firstname.' '. strtoupper(substr($profile->middleinitial,0,1)).'. '.$profile->lastname;
            }else{
                $model->receivedBy="";
            }
            if(\Yii::$app->request->isAjax){
                return $this->renderAjax('createReferral', [
                    'model' => $model,
                    'labreferral' => $labreferral,
                    'discountreferral' => $discountreferral,
                    'purposereferral' => $purposereferral,
                    'modereleasereferral' => $modereleasereferral,
                ]);
            }else{
                return $this->renderAjax('createReferral', [
                    'model' => $model,
                    'labreferral' => $labreferral,
                    'discountreferral' => $discountreferral,
                    'purposereferral' => $purposereferral,
                    'modereleasereferral' => $modereleasereferral,
                ]);
            }
        }
    }

    /**
     * Updates an existing Request model.
     * If update is successful, the browser will be redirected to the 'view' page.
     * @param integer $id
     * @return mixed
     */
    public function actionUpdatereferral($id)
    {
        //$model = $this->findModel($id);
        $model= exRequestreferral::findOne($id);
        $modelReferralrequest = ReferralRequest::find()->where('request_id = :requestId', [':requestId' => $id])->one();
        $connection= Yii::$app->labdb;
        $refcomponent = new ReferralComponent();
        
        $labreferral = ArrayHelper::map(json_decode($refcomponent->listLabreferral()), 'lab_id', 'labname');
        $discountreferral = ArrayHelper::map(json_decode($refcomponent->listDiscountreferral()), 'discount_id', 'type');
        $purposereferral = ArrayHelper::map(json_decode($refcomponent->listPurposereferral()), 'purpose_id', 'name');
        $modereleasereferral = ArrayHelper::map(json_decode($refcomponent->listModereleasereferral()), 'modeofrelease_id', 'mode');

        $sampleCount = Sample::find()->where('request_id =:requestId',[':requestId'=>$id])->count();
        $analysisCount = Analysis::find()->where('request_id =:requestId',[':requestId'=>$id])->count();

        $oldLabId = $model->lab_id;
        $notified = !empty($modelReferralrequest->notified) ? $modelReferralrequest->notified : 0;
        $samplefail = null;
        $analysisfail = null;

        if ($model->load(Yii::$app->request->post())) {
            $transaction = $connection->beginTransaction();
            if ($model->save()){
                $modelReferralrequest->request_id = $model->request_id;
                $modelReferralrequest->sample_receive_date = date('Y-m-d h:i:s',strtotime($model->sample_receive_date));
                $modelReferralrequest->receiving_agency_id = Yii::$app->user->identity->profile->rstl_id;
                //$modelReferralrequest->testing_agency_id = null;
                //$modelReferralrequest->referral_type_id = 1;
                if ($modelReferralrequest->save()){
                    $transaction->commit();
                } else {
                    $transaction->rollBack();
                    $model->getErrors();
                    return false;
                }
            }
        } else {
            $model->sample_receive_date = !empty($modelReferralrequest->sample_receive_date) ? $modelReferralrequest->sample_receive_date : null;
            if($model->request_ref_num){
                $model->request_ref_num=NULL;
            }
            if(\Yii::$app->request->isAjax){
                return $this->renderAjax('updateReferral', [
                    'model' => $model,
                    'labreferral' => $labreferral,
                    'discountreferral' => $discountreferral,
                    'purposereferral' => $purposereferral,
                    'modereleasereferral' => $modereleasereferral,
                    'notified'=>$notified,
                ]);
            }else{
                return $this->renderAjax('updateReferral', [
                    'model' => $model,
                    'labreferral' => $labreferral,
                    'discountreferral' => $discountreferral,
                    'purposereferral' => $purposereferral,
                    'modereleasereferral' => $modereleasereferral,
                    'notified'=>$notified,
                ]);
            }
        }
    }
    //get referral customer list
    public function actionReferralcustomerlist($query = null, $id = null)
    {
        if (!is_null($query)) {
            $apiUrl='http://localhost/eulimsapi.onelab.ph/api/web/referral/customers/searchname?keyword='.$query;
            $curl = new curl\Curl();
            $show = $curl->get($apiUrl);
        } else {
            $show = ['results' => ['id' => '', 'text' => '']];
        }

        return $show;
    }
}
