<?php
define ('UPDATE_SCRIPT', true);
class MotoShopAssigner{
	private $ids;
	private $templates;
	private $_defaults = array(
		'webapiurl' 	=> 'http://api.templatemonster.com/webapi/template_xml.php',
        'moto_db_name' => 'motoshop',
        'moto_db_login' => 'root',
        'moto_db_pass' => '',
        'moto_db_host' => 'localhost',
	);
	private $_template = array();
	private $_options = array();
	public function __construct($config = array()) {

		$this->_options = array_merge($this->_defaults, $config);
		//$this->_checkTable();
		$prefix = Database::instance()->table_prefix();

		$query = <<<query
        SELECT
            template_id
        FROM
           `{$prefix}templatecategories_templates`
        WHERE
           `{$prefix}templatecategories_templates`.`templatecategory_id` = 99999
       
query;

		$results = Database::instance()->query($query)->as_array(false);
		$results[]= 50900;
		$this->ids = array_map(create_function('$item', 'return $item["template_id"];'), $results);

		$this->templates = $this->findAllLocalTemplates();

	}


	public function url_get_contents ($Url) {
	    if (!function_exists('curl_init')){ 
	        die('CURL is not installed!');
	    }
	    $ch = curl_init();
	    curl_setopt($ch, CURLOPT_URL, $Url);
	    curl_setopt($ch, CURLOPT_PROXY, '192.168.5.111:3128');
	    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
	    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
	    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	    $output = curl_exec($ch);
	    curl_close($ch);
	    return $output;
	}

	private function _getConnectionToShop() {
		$dbhost = $this->_options['moto_db_host'];
		$dbuser = $this->_options['moto_db_login'];
		$dbpass = $this->_options['moto_db_pass'];
		$dbname = $this->_options['moto_db_name'];
		$dbh = new PDO("mysql:host=$dbhost;dbname=$dbname", $dbuser, $dbpass);
		$dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		return $dbh;
	}


	public function parseXML($xml){
		$SXE = new SimpleXMLElement($xml);
		return $SXE;
	}

	public function getTemplateInfo($templateId){
		$url = $this->_options['webapiurl'];
		$user = 'flashmoto';
		$pass =  'd44b22acc1b53e905ff4e7ec389acea2';
		$webapiUrl = $url . '?login=' . $user .  '&webapipassword=' . $pass . '&template_number=' . $templateId;
		return $this->url_get_contents($webapiUrl);
	}

	public function getProperties($SXE, $name){
		$properties = array();
		$xpath = '/templates/template/properties/property/propertyName[.="' . $name . '"]/following-sibling::propertyValues/propertyValue';
		foreach ($SXE->xpath($xpath) as $value){
			$prop =  (array)$value;
			$properties[] = $prop[0];
		}
		return $properties;


	}
	public function getCategories($SXE){
		$properties = array();
		$xpath = '/templates/template/categories/category/category_name';
		foreach ($SXE->xpath($xpath) as $value){
			$prop =  (array)$value;
			$properties[] = $prop[0];
		}
		return $properties;
	}

	public function getScreenshots($SXE){
	$screenshots = array();
	$xpath = '/templates/template/screenshots_list/screenshot/uri';

	foreach ($SXE->xpath($xpath) as $value){
		$scr =  (array)$value;
		$screenshots[] = $scr[0];
	}
	return $screenshots;
	}

	public function checkTemplate($template){	
			$this->_template['id'] = $template->id;
			$this->_template['name'] = $template->id;
			$this->_template['type_id'] = $template->templatetype_id;
			$this->_template['visible'] = !($template->disabled);
			$this->_template['price'] = $template->price;
			$this->_template['date_added'] = $template->inserted_date;
			$this->_template['description'] = 0;
			$this->_addTemplate();		
		
	}
	
	private function findAllLocalTemplates() {
		$templates = ORM::factory('template')->
			where('id >', 25505)->
			notin('id', $this->ids)->
			orderby('inserted_date', 'desc')->
			find_all();
		return $templates;
	}

	public function updatePrice() {
		echo "updating price...\n";
		foreach ($this->templates as $template) {
			echo  $template->id . "\n";
			$templ = $this->getTemplateInfo($template->id);
			if ($templ !== 'false') {
				$templ = $this->parseXML($templ);
				
				if (!$this->checkForPrice($template, $templ)) {
					$this->changePrice($template->id, $templ->template->price);
				}
			}
		}
	}

	public function updateDeleted() {
		echo "updating deleted...\n";
		foreach ($this->templates as $template) {
			echo  $template->id . "\n";
			$templ = $this->getTemplateInfo($template->id);
			if ($templ == 'false') {
				$this->changeDisabledField($template->id);
			}
		}
	}

	private function changeDisabledField($templateId) {
		$template = ORM::factory('template', $templateId);
		$template->disabled = 1;
		$template->save();
	}

	private function checkForPrice($LocalTemplate, $WebSitetemplate) {
		if($LocalTemplate->price != $WebSitetemplate->template->price) {
			return false;
		}
		return true;
	}

	private function changePrice($templateId, $price) {
		$template = ORM::factory('template', $templateId);
		$template->price = $price;
		$template->save();
	}

	public static function assignTemplateToCategory ($tid)
	{

		$prefix = Database::instance()->table_prefix();
		$categoy = 99999;
		try {
		//	Database::instance()->insert("templatecategories_templates", array("template_id" => $tid, "templatecategory_id" => $categoy));
		} catch (Kohana_Database_Exception $ex) {
			echo 'Cannot assign category \"All\" to template : ' .  $tid;
			echo $ex->getMessage();
		}
	}

	private function _addCategory($categoy)
	{
		

		$checkTemplateExistingQuery = "SELECT `id` FROM " 
						. 'categories' .
						" WHERE `id` = :id";

		$sql = "INSERT IGNORE INTO
			". 'categories' ."
			 (id,name)
			 VALUES
			 (:id,:name)";

		
		try {
			$db = $this->_getConnectionToShop();
					
			//Check if template not exist
			$checkStmt = $db->prepare($checkTemplateExistingQuery);
			$checkStmt->bindParam('id', $categoy['id']);
			$checkStmt->execute();
			$resultArray = $checkStmt->fetch(PDO::FETCH_ASSOC);
		
			if (count($resultArray) > 0 && isset($resultArray['id']) ) {
				return;
			}
			$stmt = $db->prepare($sql);
			$stmt->bindParam("id", $categoy['id']);
			$stmt->bindParam("name", $categoy['url_name']);			
			$stmt->execute();
			$db = null;

		} catch(PDOException $e) {

			echo $e->getMessage();
		}
	}
	private function _addCategoriesAssign($relation)
	{

		$sql = "INSERT IGNORE INTO
			". 'categories_templates' ."
			 (category_id, template_id)
			 VALUES
			 (:c_id,:t_id)";

		
		try {
			$db = $this->_getConnectionToShop();
			$stmt = $db->prepare($sql);
			$stmt->bindParam("c_id", $relation['templatecategory_id']);
			$stmt->bindParam("t_id", $relation['template_id']);			
			$stmt->execute();
			$db = null;

		} catch(PDOException $e) {

			echo $e->getMessage();
		}
	}

	private function _addTemplate()
	{
		$template = $this->_template;

		$checkTemplateExistingQuery = "SELECT `id` FROM " 
						. 'templates' .
						" WHERE `id` = :id";

		$sql = "INSERT IGNORE INTO
			". 'templates' ."
			 (id,name,type_id,visible,price,date_added,description)
			 VALUES
			 (:id,:name,:type_id,:visible,:price,:date_added,:description)";

		
		try {
			$db = $this->_getConnectionToShop();
					
			//Check if template not exist
			$checkStmt = $db->prepare($checkTemplateExistingQuery);
			$checkStmt->bindParam('id', $template['id']);
			$checkStmt->execute();
			$resultArray = $checkStmt->fetch(PDO::FETCH_ASSOC);
		
			if (count($resultArray) > 0 && isset($resultArray['id']) ) {
				return;
			}
			$stmt = $db->prepare($sql);
			$stmt->bindParam("id", $template['id']);
			$stmt->bindParam("name", $template['name']);
			$stmt->bindParam("type_id", $template['type_id']);
			$stmt->bindParam("visible", $template['visible']);
			$stmt->bindParam("date_added", $template['date_added']);
			$stmt->bindParam("price", $template['price']);
			$stmt->bindParam("description", $template['description']);
			$stmt->execute();
			$db = null;


		} catch(PDOException $e) {

			echo $e->getMessage();
		}
	}
}

function autostart ()
{
	error_reporting (E_ALL);
	ini_set ('memory_limit', '256M');
	Zend_Registry::getInstance ()->Environment = new Environment_CommandLine ();
	$updater = new Shell_Setup_Update ();
	// $updater->run ();
	$assigner = new MotoShopAssigner();
	$assigner->updatePrice();
	$assigner->updateDeleted();
}
set_time_limit (0);
$autostart = 'autostart';
include_once 'index.php';


