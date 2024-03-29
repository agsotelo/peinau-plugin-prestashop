<?php
/**
 * MIT License
 * Copyright (c) 2017 Peinau
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 */

class PeinauConfirmationModuleFrontController extends ModuleFrontController
{
    public function postProcess()
    {

        $self_url = Context::getContext()->cookie->selfurl;
        PrestaShopLogger::addLog($self_url);

        $access_token = Context::getContext()->cookie->access_token;
        $access_token_exp = Context::getContext()->cookie->access_token_exp;

        $peinauapi = new PeinauAPI();
        if ($access_token_exp - time() <= 10) {

            if (Configuration::get("PEINAU_DEBUG_MODE") == true) {
                PrestaShopLogger::addLog("ACCESS TOKEN EXPIRED!");
            }

            $response = $peinauapi->getAccessToken(Configuration::get("PEINAU_ENDPOINT_URL"), Configuration::get("PEINAU_IDENTIFIER"), Configuration::get("PEINAU_SECRET_KEY"));

            if ($response == null) {
                return $this->displayError('An error occurred while trying to make the payment');
            }

            if (Configuration::get("PEINAU_DEBUG_MODE") == true) {
                PrestaShopLogger::addLog("access token resp: " . $response);
            }

            $jsonRToken = Tools::jsonDecode($response);

            $access_token = $jsonRToken->access_token;

            if ($access_token == null) {
                return $this->displayError('An error occurred while trying to redirect the customer');
            }

            Context::getContext()->cookie->__set('access_token', $access_token);
            Context::getContext()->cookie->__set('access_token_exp', $jsonRToken->expires_in);
        }

        $response = $peinauapi->getWithToken($self_url, Context::getContext()->cookie->access_token);

        if (Configuration::get("PEINAU_DEBUG_MODE") == true) {
            PrestaShopLogger::addLog("self url resp: " . $response);
        }

        $jsonRIntent = Tools::jsonDecode($response);

        if ($jsonRIntent->state == "canceled") {
            return $this->displayError('El pago ha sido anulado');
        } else if ($jsonRIntent->state == "paid") {
            $this->paid();
        } else if ($jsonRIntent->state == "captured") {
            $access_token = Context::getContext()->cookie->access_token;
            $cart = Context::getContext()->cart;

            $transaction_detail = PeinauAPI::createTransactionReq($cart, "QUICKPAY_TOKEN", $jsonRIntent->id);
            if (Configuration::get("PEINAU_DEBUG_MODE") == true) {
                PrestaShopLogger::addLog("Intent req : " . $transaction_detail);
            }

            $response = $peinauapi->paymentIntent(Configuration::get("PEINAU_ENDPOINT_URL"), $access_token, $transaction_detail);

            if (Configuration::get("PEINAU_DEBUG_MODE") == true) {
                PrestaShopLogger::addLog("Intent resp : " . $response);
            }

            $jsonRIntent = Tools::jsonDecode($response);

            Context::getContext()->cookie->__set('selfurl', $jsonRIntent->links[0]->href);

            if (Configuration::get("PEINAU_DEBUG_MODE") == true) {
                PrestaShopLogger::addLog("Redirect to : " . $jsonRIntent->links[1]->href);
            }

            $silent_url = $jsonRIntent->links[3]->href;

            if (Configuration::get("PEINAU_DEBUG_MODE") == true) {
                PrestaShopLogger::addLog("Silent charge url : " . $silent_url);
            }

            if (Configuration::get("PEINAU_DEBUG_MODE") == true) {
                PrestaShopLogger::addLog("JWT Token : " . $access_token);
            }

            $response = $peinauapi->postWithToken($silent_url, $access_token);

            if (Configuration::get("PEINAU_DEBUG_MODE") == true) {
                PrestaShopLogger::addLog("Silent charge resp : " . $response);
            }

            $jsonRIntent = Tools::jsonDecode($response);
            if ($jsonRIntent->state == "paid") {
                $this->paid();
            } else {
                /**
                 * An error occured and is shown on a new page.
                 */
                $this->errors[] = $this->module->l('An error occurred while trying to make the payment');
                return $this->setTemplate('error.tpl');
            };
        } else {
            /**
             * An error occured and is shown on a new page.
             */
            $this->errors[] = $this->module->l('An error occurred while trying to make the payment');
            return $this->setTemplate('error.tpl');
        }
    }

    protected function paid(){
        $cart_id=Context::getContext()->cart->id;
        $secure_key=Context::getContext()->customer->secure_key;

        $cart = new Cart((int)$cart_id);
        $customer = new Customer((int)$cart->id_customer);

        /**
         * Since it's an example we are validating the order right here,
         * You should not do it this way in your own module.
         */
        $payment_status = Configuration::get('PS_OS_PAYMENT'); // Default value for a payment that succeed.
        $message = null; // You can add a comment directly into the order so the merchant will see it in the BO.

        /**
         * Converting cart into a valid order
         */

        $module_name = $this->module->displayName;
        $currency_id = (int)Context::getContext()->currency->id;

        $this->module->validateOrder($cart_id, $payment_status, $cart->getOrderTotal(), $module_name, $message, array(), $currency_id, false, $secure_key);

        /**
         * If the order has been validated we try to retrieve it
         */
        $order_id = Order::getOrderByCartId((int)$cart->id);

        if ($order_id && ($secure_key == $customer->secure_key)) {
            /**
             * The order has been placed so we redirect the customer on the confirmation page.
             */

            $module_id = $this->module->id;
            Tools::redirect('index.php?controller=order-confirmation&id_cart='.$cart_id.'&id_module='.$module_id.'&id_order='.$order_id.'&key='.$secure_key);
        } else {
            /**
             * An error occured and is shown on a new page.
             */
            $this->errors[] = $this->module->l('An error occurred while trying to make the payment');
            return $this->setTemplate('error.tpl');
        }
    }

    protected function displayError($message, $description = false)
    {
        $peinauerrors = array($this->module->l($message));
        $this->context->smarty->assign('peinauerrors', $peinauerrors);

        if (version_compare(_PS_VERSION_, '1.7', '<')) {
            return $this->setTemplate('error.16.tpl');
        } else {
            return $this->setTemplate('module:peinau/views/templates/front/error.tpl');
        }
    }
}
