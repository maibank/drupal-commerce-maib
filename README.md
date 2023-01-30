INTRODUCTION
------------

This module provides the integration of the **maib** Payment Gateway with Drupal Commerce.

To download the module and access more info go to https://www.drupal.org/project/commerce_maib

Installation guide with images: https://www.drupal.org/docs/commerce-maib-guide/commerce-maib-documentation

REQUIREMENTS
------------

Drupal: v.8 || v.9

Commerce module v.2: https://www.drupal.org/project/commerce

Maib Library: https://packagist.org/packages/maib/maibapi

INSTALLATION
------------

 * Install as you would typically install a contributed Drupal module. 

Visit https://www.drupal.org/docs/extending-drupal/installing-modules for further information.

CONFIGURATION
-------------

After installation, add a new payment Gateway on admin/commerce/config/payment-gateways:
1. Select MAIB (Off-site redirect) Plugin.
2. Give name, display name.
3. Select the mode (Default is Test).
4. Send an email with your IP and Callback URLs from "Return and cancel URLs to be provided to bank" to ecom@maib.md.
It is necessary to have access to the bank server.
5. Set the paths to certificate key, certificate and password.
 For tests use Test Private Key, Test Certificate Pass and Test Certificate- they are indicated near the fields.
6. Select the Transaction type.
  
   a) Capture (capture payment immediately after customer's approval)
   
   b) Authorize (requires manual or automated capture after checkout)
7. Check the Log debug info checkbox and set the path you want to save the logs.
When you are done testing, the bank will be required the Logs, before setting Live Mode.
8. Set up the conditions you want.
9. Set the Status to Enabled
10. Save the configuration form.

CRON & CLOSING OF BUSINESS DAY 
------------------------------

Bussines day is closed automatically by cron jobs. 

Please make sure drupal cron is setup correctly and is run at least once every day, preferable around midnight. 

USAGE
-----
Try to pay with the new Payment Gateway.

In the test mode you need to use only the test card:

      Card number: 5102180060101124
      Expiration MM/YY: 06/28
      CVV: 760

After successful payment, you will be redirected to the callback URL.

To change the mode to Live, need to send the logs to the bank.
1. The Bank will send you your own certificate.
2. Need to generate the key and certificate based on this.

INSTRUCTIONS TO EXTRACT KEYS FROM PFX FILE

Use openssl to extract keys from PFX file and password provided by bank
        
        # Public key chain:
          openssl pkcs12 -in certname.pfx -nokeys -out cert.pem
        # The private key with password:
          openssl pkcs12 -in certname.pfx -nocerts -out key.pem
        # Or optionally without a password:
          openssl pkcs12 -in certname.pfx -nocerts -out key.pem -nodes
        * CentOS note, curl+nss requires rsa + des3 for private key:
          openssl rsa -des3 -in key.pem -out key-des3.pem

3. Upload these to the server folder.
4. Change the paths to a new certificate and key.

MAINTAINERS
===========

Current maintainers:

 * [Constantin](https://github.com/kostealupu)
 * [Indrivo](https://github.com/indrivo)
