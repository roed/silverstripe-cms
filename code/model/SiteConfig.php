<?php
/**
 * Sitewide configuration.
 * 
 * h2. Translation
 * 
 * To enable translation of configurations alongside the {@link Translatable} extension.
 * This also allows assigning language-specific toplevel permissions for viewing and editing
 * pages, in addition to the normal `TRANSLATE_*`/`TRANSLATE_ALL` permissions.
 * 
 * 	Object::add_extension('SiteConfig', 'Translatable');
 *
 * @author Tom Rix
 * @package cms
 */
class SiteConfig extends DataObject implements PermissionProvider {
	static $db = array(
		"Title" => "Varchar(255)",
		"Tagline" => "Varchar(255)",
		"Theme" => "Varchar(255)",
		"CanViewType" => "Enum('Anyone, LoggedInUsers, OnlyTheseUsers', 'Anyone')",
		"CanEditType" => "Enum('LoggedInUsers, OnlyTheseUsers', 'LoggedInUsers')",
		"CanCreateTopLevelType" => "Enum('LoggedInUsers, OnlyTheseUsers', 'LoggedInUsers')",
	);
	
	static $many_many = array(
		"ViewerGroups" => "Group",
		"EditorGroups" => "Group",
		"CreateTopLevelGroups" => "Group"
	);
	
	protected static $disabled_themes = array();
	
	public static function disable_theme($theme) {
		self::$disabled_themes[$theme] = $theme;
	}
	
	/**
	 * Get the fields that are sent to the CMS. In
	 * your extensions: updateCMSFields($fields)
	 *
	 * @return FieldList
	 */
	function getCMSFields() {
		Requirements::javascript(CMS_DIR . "/javascript/SitetreeAccess.js");

		$groupsMap = Group::get()->map('ID', 'Breadcrumbs')->toArray();
		asort($groupsMap);

		$fields = new FieldList(
			new TabSet("Root",
				$tabMain = new Tab('Main',
					$titleField = new TextField("Title", _t('SiteConfig.SITETITLE', "Site title")),
					$taglineField = new TextField("Tagline", _t('SiteConfig.SITETAGLINE', "Site Tagline/Slogan")),
					$themeDropdownField = new DropdownField("Theme", _t('SiteConfig.THEME', 'Theme'), $this->getAvailableThemes())
				),
				$tabAccess = new Tab('Access',
					$viewersOptionsField = new OptionsetField("CanViewType", _t('SiteConfig.VIEWHEADER', "Who can view pages on this site?")),
					$viewerGroupsField = ListboxField::create("ViewerGroups", _t('SiteTree.VIEWERGROUPS', "Viewer Groups"))
						->setMultiple(true)->setSource($groupsMap),
					$editorsOptionsField = new OptionsetField("CanEditType", _t('SiteConfig.EDITHEADER', "Who can edit pages on this site?")),
					$editorGroupsField = ListboxField::create("EditorGroups", _t('SiteTree.EDITORGROUPS', "Editor Groups"))
						->setMultiple(true)->setSource($groupsMap),
					$topLevelCreatorsOptionsField = new OptionsetField("CanCreateTopLevelType", _t('SiteConfig.TOPLEVELCREATE', "Who can create pages in the root of the site?")),
					$topLevelCreatorsGroupsField = ListboxField::create("CreateTopLevelGroups", _t('SiteTree.TOPLEVELCREATORGROUPS', "Top level creators"))
						->setMultiple(true)->setSource($groupsMap)
				)
			)
		);

		$themeDropdownField->setEmptyString(_t('SiteConfig.DEFAULTTHEME', '(Use default theme)'));

		$viewersOptionsSource = array();
		$viewersOptionsSource["Anyone"] = _t('SiteTree.ACCESSANYONE', "Anyone");
		$viewersOptionsSource["LoggedInUsers"] = _t('SiteTree.ACCESSLOGGEDIN', "Logged-in users");
		$viewersOptionsSource["OnlyTheseUsers"] = _t('SiteTree.ACCESSONLYTHESE', "Only these people (choose from list)");
		$viewersOptionsField->setSource($viewersOptionsSource);
		
		$editorsOptionsSource = array();
		$editorsOptionsSource["LoggedInUsers"] = _t('SiteTree.EDITANYONE', "Anyone who can log-in to the CMS");
		$editorsOptionsSource["OnlyTheseUsers"] = _t('SiteTree.EDITONLYTHESE', "Only these people (choose from list)");
		$editorsOptionsField->setSource($editorsOptionsSource);
		
		$topLevelCreatorsOptionsField->setSource($editorsOptionsSource);
		
		// Translatable doesn't handle updateCMSFields on DataObjects,
		// so add it here to save the current Locale,
		// because onBeforeWrite does not work.
		if(class_exists('Translatable') && Object::has_extension('SiteConfig',"Translatable")){ 
			$fields->push(new HiddenField("Locale"));
		}

		if (!Permission::check('EDIT_SITECONFIG')) {
			$fields->makeFieldReadonly($viewersOptionsField);
			$fields->makeFieldReadonly($viewerGroupsField);
			$fields->makeFieldReadonly($editorsOptionsField);
			$fields->makeFieldReadonly($editorGroupsField);
			$fields->makeFieldReadonly($topLevelCreatorsOptionsField);
			$fields->makeFieldReadonly($topLevelCreatorsGroupsField);
			$fields->makeFieldReadonly($taglineField);
			$fields->makeFieldReadonly($titleField);
		}

		if(file_exists(BASE_PATH . '/install.php')) {
			$fields->addFieldToTab("Root.Main", new LiteralField("InstallWarningHeader", 
				"<p class=\"message warning\">" . _t("SiteTree.REMOVE_INSTALL_WARNING", 
				"Warning: You should remove install.php from this SilverStripe install for security reasons.")
				. "</p>"), "Title");
		}
		
		$tabMain->setTitle(_t('SiteConfig.TABMAIN', "Main"));
		$tabAccess->setTitle(_t('SiteConfig.TABACCESS', "Access"));
		$this->extend('updateCMSFields', $fields);
		
		return $fields;
	}

	/**
	 * Get all available themes that haven't been marked as disabled.
	 * @param string $baseDir Optional alternative theme base directory for testing
	 * @return array of theme directory names
	 */
	public function getAvailableThemes($baseDir = null) {
		$themes = SSViewer::get_themes($baseDir);
		foreach(self::$disabled_themes as $theme) {
			if(isset($themes[$theme])) unset($themes[$theme]);
		}
		return $themes;
	}
	
	/**
	 * Get the actions that are sent to the CMS. In
	 * your extensions: updateEditFormActions($actions)
	 *
	 * @return Fieldset
	 */
	function getCMSActions() {
		if (Permission::check('ADMIN') || Permission::check('EDIT_SITECONFIG')) {
			$actions = new FieldList(
				FormAction::create('save_siteconfig', _t('CMSMain.SAVE','Save'))
					->addExtraClass('ss-ui-action-constructive')->setAttribute('data-icon', 'accept')
			);
		} else {
			$actions = new FieldList();
		}
		
		$this->extend('updateCMSActions', $actions);
		
		return $actions;
	}
	
	/**
	 * Get the current sites SiteConfig, and creates a new one
	 * through {@link make_site_config()} if none is found.
	 *
	 * @param string $locale
	 * @return SiteConfig
	 */
	static function current_site_config($locale = null) {
		if(class_exists('Translatable') && Object::has_extension('SiteConfig',"Translatable")){
			$locale = isset($locale) ? $locale : Translatable::get_current_locale();
			$siteConfig = Translatable::get_one_by_locale('SiteConfig', $locale);
		} else {
			$siteConfig = DataObject::get_one('SiteConfig');
		}
		
		if (!$siteConfig) $siteConfig = self::make_site_config($locale);
		
		return $siteConfig;
	}
	
	/**
	 * Setup a default SiteConfig record if none exists
	 */
	function requireDefaultRecords() {
		parent::requireDefaultRecords();
		$siteConfig = DataObject::get_one('SiteConfig');
		if(!$siteConfig) {
			self::make_site_config();
			DB::alteration_message("Added default site config","created");
		}
	}
	
	/**
	 * Create SiteConfig with defaults from language file.
	 * if Translatable is enabled on SiteConfig, see if one already exist
	 * and use those values for the translated defaults. 
	 * 
	 * @param string $locale
	 * @return SiteConfig
	 */
	static function make_site_config($locale = null) {
		if(class_exists('Translatable') && !$locale) $locale = Translatable::get_current_locale();
		
		$siteConfig = new SiteConfig();
		$siteConfig->Title = _t('SiteConfig.SITENAMEDEFAULT',"Your Site Name");
		$siteConfig->Tagline = _t('SiteConfig.TAGLINEDEFAULT',"your tagline here");

		if(class_exists('Translatable') && $siteConfig->hasExtension('Translatable')){
			Translatable::disable_locale_filter();
			$defaultConfig = SiteConfig::get()->first();
			Translatable::enable_locale_filter();			
			
			if($defaultConfig){					
				return $defaultConfig->createTranslation($locale);
			}			
			
			// TODO Copy view/edit group settings
			
			// set the correct Locale
			$siteConfig->Locale = $locale;
		}

		$siteConfig->write();
		
		return $siteConfig;
 	}
	
	/**
	 * Can a user view pages on this site? This method is only
	 * called if a page is set to Inherit, but there is nothing
	 * to inherit from.
	 *
	 * @param mixed $member 
	 * @return boolean
	 */
	public function canView($member = null) {
		if(!$member) $member = Member::currentUserID();
		if($member && is_numeric($member)) $member = DataObject::get_by_id('Member', $member);

		if ($member && Permission::checkMember($member, "ADMIN")) return true;

		if (!$this->CanViewType || $this->CanViewType == 'Anyone') return true;
				
		// check for any logged-in users
		if($this->CanViewType == 'LoggedInUsers' && $member) return true;

		// check for specific groups
		if($this->CanViewType == 'OnlyTheseUsers' && $member && $member->inGroups($this->ViewerGroups())) return true;
		
		return false;
	}
	
	/**
	 * Can a user edit pages on this site? This method is only
	 * called if a page is set to Inherit, but there is nothing
	 * to inherit from.
	 *
	 * @param mixed $member 
	 * @return boolean
	 */
	public function canEdit($member = null) {
		if(!$member) $member = Member::currentUserID();
		if($member && is_numeric($member)) $member = DataObject::get_by_id('Member', $member);

		if ($member && Permission::checkMember($member, "ADMIN")) return true;

		// check for any logged-in users
		if(!$this->CanEditType || $this->CanEditType == 'LoggedInUsers' && $member) return true;

		// check for specific groups
		if($this->CanEditType == 'OnlyTheseUsers' && $member && $member->inGroups($this->EditorGroups())) return true;
		
		return false;
	}
	
	function providePermissions() {
		return array(
			'EDIT_SITECONFIG' => array(
				'name' => _t('SiteConfig.EDIT_PERMISSION', 'Manage site configuration'),
				'category' => _t('Permissions.PERMISSIONS_CATEGORY', 'Roles and access permissions'),
				'help' => _t('SiteConfig.EDIT_PERMISSION_HELP', 'Ability to edit global access settings/top-level page permissions.'),
				'sort' => 400
			)
		);
	}
	
	/**
	 * Can a user create pages in the root of this site?
	 *
	 * @param mixed $member 
	 * @return boolean
	 */
	public function canCreateTopLevel($member = null) {
		if(!$member || !(is_a($member, 'Member')) || is_numeric($member)) {
			$member = Member::currentUserID();
		}
		
		if (Permission::check('ADMIN')) return true;

		if ($member && Permission::checkMember($member, "ADMIN")) return true;
		
		// check for any logged-in users
		if($this->CanCreateTopLevelType == 'LoggedInUsers' && $member) return true;
		
		// check for specific groups
		if($member && is_numeric($member)) $member = DataObject::get_by_id('Member', $member);
		if($this->CanCreateTopLevelType == 'OnlyTheseUsers' && $member && $member->inGroups($this->CreateTopLevelGroups())) return true;
		

		return false;
	}
}
