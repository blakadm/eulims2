<?php
use yii\helpers\Html;
use yii\widgets\ActiveForm;
use kartik\select2\Select2;
use yii\helpers\ArrayHelper;
use common\models\services\Testcategory;
use common\models\lab\Lab;
use kartik\widgets\DepDrop;
use yii\helpers\Url;

/* @var $this yii\web\View */
/* @var $model common\models\services\Sampletype */
/* @var $form yii\widgets\ActiveForm */

$LabList= ArrayHelper::map(Testcategory::find()->orderBy('category_name')->all(),'testcategory_id','category_name');
$Lab= ArrayHelper::map(Lab::find()->all(),'lab_id','labname');

?>

<div class="sample-type-form" style="padding-bottom: 10px">
<?php $form = ActiveForm::begin(); ?>

<div class="sampletype-form" style="padding-bottom: 10px">

    <?php $form = ActiveForm::begin(); ?>

    <?= $form->field($model, 'lab_id')->widget(Select2::classname(), [
                'data' => $Lab,
                'language' => 'en',
                'options' => ['placeholder' => 'Select lab'],
                'pluginOptions' => [
                    'allowClear' => true
                ],
    ])->label("Lab"); ?>

   <?php
        //  echo "<pre>";
        //  print_r($LabList);
        //  echo "</pre>";
        //  exit;
    ?>
    <?= $form->field($model, 'testcategory_id')->widget(Select2::classname(), [
                'data' => $LabList,
                'language' => 'en',
                'options' => ['placeholder' => 'Select Test Category'],
                'pluginOptions' => [
                    'allowClear' => true
                ],
    ])->label("Test Category"); ?>

 

    <?= $form->field($model, 'sample_type')->textInput(['maxlength' => true]) ?>

  

    <div class="form-group pull-right">
        <?php if(Yii::$app->request->isAjax){ ?>
            <button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>
        <?php } ?>
        <?= Html::submitButton($model->isNewRecord ? 'Create' : 'Update', ['class' => $model->isNewRecord ? 'btn btn-success' : 'btn btn-primary']) ?>
    </div>

    <?php ActiveForm::end(); ?>

</div>
