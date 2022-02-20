# Wordpress woocommerce plugin
shurjoPay version two woocommerce plugin
# Plugin configuration

Disclaimer:
	
	If you are migrating from shurjoPay woocommerce plugin version one to a higher version, then you need to extend the checkout page fields.
	The current version requires to must have additional fields unlike the previous version. Such as:
<img src="https://user-images.githubusercontent.com/57352037/154837063-e040923e-ee1b-4c43-abed-7e1f0db37bf6.png" width="200">


<b>Steps for sandbox (test_server) integration:</b>

Step 1: 

	Go to: https://github.com/shurjoPay-Plugins/woocommerce
	Download the zip and extract it.
	
<img src="https://user-images.githubusercontent.com/57352037/152670818-418bb5a2-e62c-4180-8579-dcd55f19b4a8.png" width="400">

	
	Now, enter into the woocommerce-main directory and select the shurjoPay folder and zip it.
	Go to: http://<YOUR-DOMAIN-NAME>/wp-admin/about.php
	Select -> Plugins -> Add new
	
<img src="https://user-images.githubusercontent.com/57352037/152670374-53b79162-f7bd-4487-9a9e-bd4a0bbe1512.png" width="350">

	Now, Press -> Upload Plugin -> Choose File -> Select the shurjoPay.zip from your local directory -> Install Now.
	
<img src="https://user-images.githubusercontent.com/57352037/152670440-6defcbea-822a-4ef6-906f-32bfe0e23b29.png" width="650">

	
  
Step 2: 

	Go to WooCommerce-> Settings-> Payments
<img src="https://user-images.githubusercontent.com/57352037/154837387-534f02c7-a67c-47a3-964e-8586bcc301d3.png" width = "280">
  
	Select Shurjopay -> Manage.
<img src="https://user-images.githubusercontent.com/57352037/154837615-7bd82dbe-c6ff-40df-820e-cd82aee6f63f.png" width = "450">
	

Step 3:

    Fill the credentials as defined below:

    a) Enable / Disable = set it enable
    b) Title = "Shurjopay"
    c) Description = "Pay securely using ShurjoPay"
    d) API Username = <--Merchant name will be provided by shurjoPay! -->
    e) API Password = Provided by shurjopay
    f) Transaction Prefix = Provided by shurjopay
    g) Payment Currency = Set to your preferred currency
    h) Payment Status = Processing
    i) IPN = no
    j) Test Mode = Enable for sandbox
    
    Setting in back panel:
<img src="https://user-images.githubusercontent.com/57352037/154837279-dc487621-ef38-4baf-b8c8-d93806f0e154.png" width="350">

