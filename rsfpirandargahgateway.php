<?php

/**
 * @package RSForm!Pro
 * @copyright (C) 2014 www.rsjoomla.com
 * @license GPL, http://www.gnu.org/licenses/gpl-2.0.html
 */

defined('_JEXEC') or die('Restricted access');

error_reporting(0);

class plgSystemRSFPIranDargahGateway extends JPlugin
{
    protected $componentId = 1020;
    protected $componentValue = 'irandargahgateway';
    protected $baseUrl = 'https://dargaah.com';
    protected $redirectURI = 'https://dargaah.com/startpay/';

    public function __construct(&$subject, $config)
    {
        parent::__construct($subject, $config);
        $this->newComponents = array(1020);
    }

    public function rsfp_bk_onAfterShowComponents()
    {
        JFactory::getLanguage()->load('plg_system_rsfpirandargahgateway', JPATH_ADMINISTRATOR);

        $formId = JFactory::getApplication()->input->getInt('formId');

        $link = "displayTemplate('" . $this->componentId . "')";
        if ($components = RSFormProHelper::componentExists($formId, $this->componentId)) {
            $link = "displayTemplate('" . $this->componentId . "', '" . $components[0] . "')";
        }

        ?>
		<li class="rsform_navtitle"><?php echo JText::_('RSFP_IRANDARGAH_GATEWAY'); ?></li>
		<li><a href="javascript: void(0);" onclick="<?php echo $link; ?>;return false;" id="rsfpc<?php echo $this->componentId; ?>"><span class="rsficon rsficon-irandargahgateway"></span><span class="inner-text"><?php echo JText::_('RSFP_IRANDARGAH_GATEWAY'); ?></span></a></li>
		<?php
}

    public function rsfp_getPayment(&$items, $formId)
    {
        if ($components = RSFormProHelper::componentExists($formId, $this->componentId)) {
            $data = RSFormProHelper::getComponentProperties($components[0]);

            $item = new stdClass();
            $item->value = $this->componentValue;
            $item->text = $data['LABEL'];

            // add to array
            $items[] = $item;
        }
    }

    /**
     * Go to payment gateway.
     */
    public function rsfp_doPayment($payValue, $formId, $SubmissionId, $price, $products, $code)
    {
        JFactory::getLanguage()->load('plg_system_rsfpirandargahgateway', JPATH_ADMINISTRATOR);

        $app = JFactory::getApplication();
        $redirectLink = JURI::root() . 'index.php?option=com_rsform&formId=' . $formId;

        // execute only for our plugin
        if ($payValue != $this->componentValue) {
            return;
        }

        $rialPrice = $this->_getRialedPrice(
            $price,
            RSFormProHelper::htmlEscape(RSFormProHelper::getConfig('irandargahgateway.currency')) == '0'
        );

        JFactory::getSession()->set('price_' . $SubmissionId, $rialPrice);

        if ($rialPrice >= 10000) {
            $callback_url = JURI::root() . 'index.php?option=com_rsform&formId=' . $formId . '&task=plugin&plugin_task=irandargahgateway.notify&code=' . $code;

            $result = $this->sendIranDargahRequest(
                $this->baseUrl . '/payment',
                [
                    'merchantID' => RSFormProHelper::getConfig('irandargahgateway.merchant_id'),
                    'amount' => $rialPrice,
                    'callbackURL' => urlencode($callback_url),
                    'orderId' => $SubmissionId,
                    'mobile' => $this->_getPayerMobile($formId, $SubmissionId),
                    'action' => 'GET',
                ]
            );

            $result = $this->processResponse($result);
            if ($result) {
                if ($result->status == 200) {
                    $app->redirect($this->redirectURI . $result->authority);
                } else if ($result->status != 200) {
                    $app->redirect($redirectLink, $this->_getErrorMessage($result->status), 'error');
                }
            } else {
                $app->redirect($redirectLink, $this->_getErrorMessage('error'), 'error');
            }
        } else {
            $app->redirect($redirectLink, $this->_getErrorMessage('price'), 'error');
        }
    }

    /**
     * On return from gateway.
     */
    public function rsfp_f_onSwitchTasks()
    {
        JFactory::getLanguage()->load('plg_system_rsfpirandargahgateway', JPATH_ADMINISTRATOR);

        if (JRequest::getVar('plugin_task') == 'irandargahgateway.notify') {
            $app = JFactory::getApplication();
            $formId = $app->input->get->get('formId', '1', 'INT');
            $redirectLink = JURI::root() . 'index.php?option=com_rsform&formId=' . $formId;

            if ($app->input->get->get('state', '', 'STRING') == 'wait_for_confirm') {
                $db = JFactory::getDBO();

                $SubmissionId = $app->input->get->get('orderId', '', 'STRING');
                $price = JFactory::getSession()->get('amount' . $SubmissionId, 0);
                $code = $app->input->get->get('code', '', 'STRING');
                $authority = $app->input->get->get('authority', '', 'STRING');

                $db->setQuery("SELECT SubmissionId FROM #__rsform_submissions s WHERE s.FormId='" . $formId . "' AND MD5(CONCAT(s.SubmissionId,s.DateSubmitted)) = '" . $db->escape($code) . "'");
                $dbSubmissionId = $db->loadResult();
                if ($SubmissionId == $dbSubmissionId) {
                    $result = $this->sendIranDargahRequest($this->baseUrl . '/verification',
                        [
                            'merchantID' => RSFormProHelper::getConfig('irandargahgateway.merchant_id'),
                            'orderId' => $SubmissionId,
                            'amount' => $price,
                            'authority' => $authority,
                        ]
                    );

                    $result = $this->processResponse($result);
                    if ($result) {
                        if ($result->status == 100) {
                            if ($result->result->state == 'paid') {
                                $db->setQuery("UPDATE #__rsform_submission_values sv SET sv.FieldValue=1 WHERE sv.FieldName='_STATUS' AND sv.FormId='" . $formId . "' AND sv.SubmissionId = '" . $SubmissionId . "'");
                                $db->execute();
                                $db->setQuery("UPDATE #__rsform_submission_values sv SET sv.FieldValue='" . $price . "' WHERE sv.FieldName='rsfp_Total' AND sv.FormId='" . $formId . "' AND sv.SubmissionId = '" . $SubmissionId . "'");
                                $db->execute();
                                $app->triggerEvent('rsfp_afterConfirmPayment', array($SubmissionId));
                                $app->redirect($redirectLink, JText::_('RSFP_IRANDARGAH_GATEWAY_PAID_SUCCESSFULLY'), 'success');
                            } else {
                                $app->redirect($redirectLink, $this->_getErrorMessage(), 'error');
                            }
                        } else {
                            $app->redirect($redirectLink, $this->_getErrorMessage($result->status), 'error');
                        }
                    } else {
                        $app->redirect($redirectLink, $this->_getErrorMessage('error'), 'error');
                    }
                } else {
                    $app->redirect($redirectLink, $this->_getErrorMessage('error'), 'error');
                }
            } else {
                $app->redirect($redirectLink, $this->_getErrorMessage('error'), 'error');
            }
        }
    }

    public function rsfp_bk_onAfterCreateComponentPreview($args = array())
    {
        if ($args['ComponentTypeName'] == 'irandargahgateway') {
            $args['out'] = '<td>&nbsp;</td>';
            $args['out'] .= '<td><span style="font-size:24px;margin-right:5px" class="rsficon rsficon-irandargahgateway"></span> ' . $args['data']['LABEL'] . '</td>';
        }
    }

    public function rsfp_bk_onAfterShowConfigurationTabs($tabs)
    {
        JFactory::getLanguage()->load('plg_system_rsfpirandargahgateway', JPATH_ADMINISTRATOR);

        $tabs->addTitle(JText::_('RSFP_IRANDARGAH_GATEWAY_LABEL'), 'form-irandargah');
        $tabs->addContent($this->irandargahGatewayConfigurationScreen());
    }

    public function irandargahGatewayConfigurationScreen()
    {
        ob_start();
        ?>

		<div id="page-irandargahgateway" class="com-rsform-css-fix">
			<table class="admintable">
				<tr>
					<td width="200" style="width: 200px;" align="right" class="key"><label for="merchantId"><?php echo JText::_('RSFP_IRANDARGAH_GATEWAY_CONFIG_MERCHANT_ID_LABEL'); ?></label></td>
					<td><textarea name="rsformConfig[irandargahgateway.merchant_id]" dir="ltr"><?php echo RSFormProHelper::htmlEscape(RSFormProHelper::getConfig('irandargahgateway.merchant_id')); ?></textarea></td>
				</tr>
				<!-- <tr>
					<td width="200" style="width: 200px;" align="right" class="key"><label for="tax.value"><?php //echo JText::_('RSFP_IRANDARGAH_GATEWAY_CONFIG_TAX_LABEL'); ?></label></td>
					<td><input type="text" name="rsformConfig[irandargahgateway.tax.value]" value="<?php //echo RSFormProHelper::htmlEscape(RSFormProHelper::getConfig('irandargahgateway.tax.value')); ?>" size="4" maxlength="5" dir="ltr">
					</td>
				</tr> -->
				<tr>
					<td width="200" style="width: 200px;" align="right" class="key"><label for="tax.label"><?php echo JText::_('RSFP_IRANDARGAH_GATEWAY_CURRENCY_TAX_LABEL'); ?></label></td>
					<td>
						<label for="rial-currency">
							<input id="rial-currency" type="radio" name="rsformConfig[irandargahgateway.currency]" value="0" <?php
if (RSFormProHelper::htmlEscape(RSFormProHelper::getConfig('irandargahgateway.currency')) == '0') {
            echo 'checked';
        }
        ?>>
							<?php
echo JText::_('RSFP_IRANDARGAH_GATEWAY_RIAL_LABEL')
        ?>
						</label>
						<br>
						<label for="toman-currency" style="display: inline;">
							<input id="toman-currency" type="radio" name="rsformConfig[irandargahgateway.currency]" value="1" <?php
if (RSFormProHelper::htmlEscape(RSFormProHelper::getConfig('irandargahgateway.currency')) == '1') {
            echo 'checked';
        }
        ?>>
							<?php
echo JText::_('RSFP_IRANDARGAH_GATEWAY_TOMAN_LABEL')
        ?>
						</label>
					</td>
				</tr>
			</table>
		</div>

		<?php

        $contents = ob_get_contents();
        ob_end_clean();
        return $contents;
    }

    protected function _getErrorMessage($error = '')
    {
        JFactory::getLanguage()->load('plg_system_rsfpirandargahgateway', JPATH_ADMINISTRATOR);

        switch ($error) {
            case 'price':
                $out = JText::_('RSFP_IRANDARGAH_GATEWAY_ERROR_PRICE');
                break;
            case 'error':
                $out = JText::_('RSFP_IRANDARGAH_GATEWAY_ERROR_ERROR');
                break;
            case 200:
                $out = JText::_('اتصال به درگاه بانک با موفقیت انجام شده است.');
                break;
            case 201:
                $out = JText::_('درحال پرداخت در درگاه بانک.');
                break;
            case 100:
                $out = JText::_('تراکنش با موفقیت انجام شده است.');
                break;
            case 101:
                $out = JText::_('تراکنش قبلا verify شده است.');
                break;
            case 404:
                $out = JText::_('تراکنش یافت نشد.');
                break;
            case 403:
                $out = JText::_('کد مرچنت صحیح نمی باشد.');
                break;
            case -3:
                $out = JText::_('URL همخوانی ندارد');
                break;
            case -2:
                $out = JText::_('اطلاعات ارسالی صحیح نمی باشد.');
                break;
            case -1:
                $out = JText::_('کاربر از انجام تراکنش منصرف شده است.');
                break;
            case -10:
                $out = JText::_('مبلغ تراکنش کمتر از 10,000 ریال است.');
                break;
            case -11:
                $out = JText::_('مبلغ تراکنش با مبلغ پرداختی یکسان نیست. مبلغ برگشت خورد.');
                break;
            case -12:
                $out = JText::_('شماره کارتی که با آن تراکنش انجام شده است با شماره کارت ارسالی مغایرت دارد. مبلغ برگشت خورد.');
                break;
            case -13:
                $out = JText::_('تراکنش تکراری است.');
                break;
            case -20:
                $out = JText::_('شناسه تراکنش یافت نشد.');
                break;
            case -21:
                $out = JText::_('مدت زمان مجاز جهت ارسال به بانک گذشته است.');
                break;
            case -22:
                $out = JText::_('تراکنش برای بانک ارسال شده است.');
                break;
            case -23:
                $out = JText::_('خطا در اتصال به درگاه بانک.');
                break;
            case -30:
                $out = JText::_('اشکالی در فرایند پرداخت ایجاد شده است.مبلغ برگشت خورد.');
                break;
            case -31:
                $out = JText::_('خطای ناشناخته');
                break;
            default:
                $out = JText::_('RSFP_IRANDARGAH_GATEWAY_ERROR_UNSUCCESSFUL') . " " . $error;
                break;
        }
        return $out;
    }

    protected function _getSubmissionValue($submissionId, $componentId)
    {
        if (is_numeric($componentId)) {
            $name = $this->_getComponentName($componentId);
        } else {
            $name = $componentId;
        }

        $db = JFactory::getDbo();
        $db->setQuery("SELECT FieldValue FROM #__rsform_submission_values WHERE SubmissionId='" . (int) $submissionId . "' AND FieldName='" . $db->escape($name) . "'");
        return $db->loadResult();
    }

    protected function _getComponentName($componentId)
    {
        $componentId = (int) $componentId;

        $db = JFactory::getDbo();
        $db->setQuery("SELECT PropertyValue FROM #__rsform_properties WHERE ComponentId='" . $componentId . "' AND PropertyName='NAME'");
        return $db->loadResult();
    }

    protected function _getSubmissionId($formId, $code)
    {
        $db = JFactory::getDbo();
        $query = $db->getQuery(true);
        $query->select($db->qn('SubmissionId'))
            ->from($db->qn('#__rsform_submissions', 's'))
            ->where($db->qn('s.FormId') . ' = ' . $db->q($formId))
            ->where('MD5(CONCAT(' . $db->qn('s.SubmissionId') . ',' . $db->qn('s.DateSubmitted') . ')) = ' . $db->q($code));
        $db->setQuery($query);

        if ($SubmissionId = $db->loadResult()) {
            return $SubmissionId;
        }

        return false;
    }

    protected function _getPayerMobile($formId, $SubmissionId)
    {
        $db = JFactory::getDbo();
        $query = $db->getQuery(true);
        $query->select('FieldValue')
            ->from($db->qn('#__rsform_submission_values'));
        $query->where(
            $db->qn('FormId') . ' = ' . $db->q($formId)
            . ' AND ' .
            $db->qn('SubmissionId') . ' = ' . $db->q($SubmissionId)
            . ' AND ' .
            $db->qn('FieldName') . ' = ' . $db->q('payer_mobile')
        );
        $db->setQuery((string) $query);
        $result = $db->loadResult();
        return $result;
    }

    /**
     *
     * @param  float  $price
     * @param  boolean  $isRial
     * @return float $rialPrice
     */
    protected function _getRialedPrice($price, $isRial)
    {
        $rialPrice = round($price, 0);
        if (!$isRial) {
            $rialPrice *= 10;
        }
        return $rialPrice;
    }

    /**
     * Send GET Request using cURL.
     *
     * @param  string  $url
     * @param  array  $array
     * @return mixed $result
     */
    protected function sendIranDargahRequest($url, $array)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, @json_encode($array));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $result;
    }

    /**
     * Process response.
     *
     * @param  mixed  $response
     * @return mixed $processedResponse|false
     */
    protected function processResponse($response)
    {
        $processedResponse = @json_decode($response);
        if (json_last_error() == JSON_ERROR_NONE) {
            return $processedResponse;
        }
        return false;
    }
}
