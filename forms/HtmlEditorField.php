<?php
/**
 * A TinyMCE-powered WYSIWYG HTML editor field with image and link insertion and tracking capabilities. Editor fields
 * are created from <textarea> tags, which are then converted with JavaScript.
 *
 * @package forms
 * @subpackage fields-formattedinput
 */
class HtmlEditorField extends TextareaField {

	/**
	 * @var Boolean Use TinyMCE's GZIP compressor
	 */
	static $use_gzip = true;

	protected $rows = 30;
	
	/**
	 * Includes the JavaScript neccesary for this field to work using the {@link Requirements} system.
	 */
	public static function include_js() {
		require_once 'tinymce/tiny_mce_gzip.php';

		$configObj = HtmlEditorConfig::get_active();

		if(self::$use_gzip) {
			$internalPlugins = array();
			foreach($configObj->getPlugins() as $plugin => $path) if(!$path) $internalPlugins[] = $plugin;
			$tag = TinyMCE_Compressor::renderTag(array(
				'url' => THIRDPARTY_DIR . '/tinymce/tiny_mce_gzip.php',
				'plugins' => implode(',', $internalPlugins),
				'themes' => 'advanced',
				'languages' => $configObj->getOption('language')
			), true);
			preg_match('/src="([^"]*)"/', $tag, $matches);
			Requirements::javascript($matches[1]);

		} else {
			Requirements::javascript(MCE_ROOT . 'tiny_mce_src.js');
		} 

		Requirements::customScript($configObj->generateJS(), 'htmlEditorConfig');
	}
	
	/**
	 * @see TextareaField::__construct()
	 */
	public function __construct($name, $title = null, $value = '') {
		if(count(func_get_args()) > 3) Deprecation::notice('3.0', 'Use setRows() and setCols() instead of constructor arguments');

		parent::__construct($name, $title, $value);
		
		self::include_js();
	}
	
	/**
	 * @return string
	 */
	function Field() {
		// mark up broken links
		$value  = new SS_HTMLValue($this->value);
		
		if($links = $value->getElementsByTagName('a')) foreach($links as $link) {
			$matches = array();
			
			if(preg_match('/\[sitetree_link id=([0-9]+)\]/i', $link->getAttribute('href'), $matches)) {
				if(!DataObject::get_by_id('SiteTree', $matches[1])) {
					$class = $link->getAttribute('class');
					$link->setAttribute('class', ($class ? "$class ss-broken" : 'ss-broken'));
				}
			}
		}
		
		return $this->createTag (
			'textarea',
			$this->getAttributes(),
			htmlentities($value->getContent(), ENT_COMPAT, 'UTF-8')
		);
	}

	function getAttributes() {
		return array_merge(
			parent::getAttributes(),
			array(
				'tinymce' => 'true',
				'style'   => 'width: 97%; height: ' . ($this->rows * 16) . 'px', // prevents horizontal scrollbars
				'value' => null,
			)
		);
	}
	
	public function saveInto($record) {
		if($record->escapeTypeForField($this->name) != 'xml') {
			throw new Exception (
				'HtmlEditorField->saveInto(): This field should save into a HTMLText or HTMLVarchar field.'
			);
		}
		
		$linkedPages = array();
		$linkedFiles = array();
		
		$htmlValue = new SS_HTMLValue($this->value);
		
		if(class_exists('SiteTree')) {
			// Populate link tracking for internal links & links to asset files.
			if($links = $htmlValue->getElementsByTagName('a')) foreach($links as $link) {
				$href = Director::makeRelative($link->getAttribute('href'));

				if($href) {
					if(preg_match('/\[sitetree_link id=([0-9]+)\]/i', $href, $matches)) {
						$ID = $matches[1];

						// clear out any broken link classes
						if($class = $link->getAttribute('class')) {
							$link->setAttribute('class', preg_replace('/(^ss-broken|ss-broken$| ss-broken )/', null, $class));
						}

						$linkedPages[] = $ID;
						if(!DataObject::get_by_id('SiteTree', $ID))  $record->HasBrokenLink = true;

					} else if(substr($href, 0, strlen(ASSETS_DIR) + 1) == ASSETS_DIR.'/') {
						$candidateFile = File::find(Convert::raw2sql(urldecode($href)));
						if($candidateFile) {
							$linkedFiles[] = $candidateFile->ID;
						} else {
							$record->HasBrokenFile = true;
						}
					} else if($href == '' || $href[0] == '/') {
						$record->HasBrokenLink = true;
					}
				}
			}
		}
		
		// Resample images, add default attributes and add to assets tracking.
		if($images = $htmlValue->getElementsByTagName('img')) foreach($images as $img) {
			// strip any ?r=n data from the src attribute
			$img->setAttribute('src', preg_replace('/([^\?]*)\?r=[0-9]+$/i', '$1', $img->getAttribute('src')));
			if(!$image = File::find($path = urldecode(Director::makeRelative($img->getAttribute('src'))))) {
				if(substr($path, 0, strlen(ASSETS_DIR) + 1) == ASSETS_DIR . '/') {
					$record->HasBrokenFile = true;
				}
				
				continue;
			}
			
			// Resample the images if the width & height have changed.
			$width  = $img->getAttribute('width');
			$height = $img->getAttribute('height');
			
			if($image){
				if($width && $height && ($width != $image->getWidth() || $height != $image->getHeight())) {
					//Make sure that the resized image actually returns an image:
					$resized=$image->ResizedImage($width, $height);
					if($resized)
						$img->setAttribute('src', $resized->getRelativePath());
				}
			}
			
			// Add default empty title & alt attributes.
			if(!$img->getAttribute('alt')) $img->setAttribute('alt', '');
			if(!$img->getAttribute('title')) $img->setAttribute('title', '');
			
			//If the src attribute is not set, then we won't add this to the list:
			if($img->getAttribute('src')){
				// Add to the tracked files.
				$linkedFiles[] = $image->ID;
			}
		}
		
		// Save file & link tracking data.
		if(class_exists('SiteTree')) {
			if($record->ID && $record->many_many('LinkTracking') && $tracker = $record->LinkTracking()) {
			    $tracker->removeByFilter(sprintf('"FieldName" = \'%s\' AND "SiteTreeID" = %d', $this->name, $record->ID));

				if($linkedPages) foreach($linkedPages as $item) {
					$SQL_fieldName = Convert::raw2sql($this->name);
					DB::query("INSERT INTO \"SiteTree_LinkTracking\" (\"SiteTreeID\",\"ChildID\", \"FieldName\")
						VALUES ($record->ID, $item, '$SQL_fieldName')");
				}
			}
		
			if($record->ID && $record->many_many('ImageTracking') && $tracker = $record->ImageTracking()) {
			    $tracker->where(sprintf('"FieldName" = \'%s\' AND "SiteTreeID" = %d', $this->name, $record->ID))->removeAll();

				$fieldName = $this->name;
				if($linkedFiles) foreach($linkedFiles as $item) {
					$tracker->add($item, array('FieldName' => $this->name));
				}
			}
		}
		
		$record->{$this->name} = $htmlValue->getContent();
	}

	/**
	 * @return HtmlEditorField_Readonly
	 */
	public function performReadonlyTransformation() {
		$field = new HtmlEditorField_Readonly($this->name, $this->title, $this->value);
		$field->setForm($this->form);
		$field->dontEscape = true;
		return $field;
	}
	
	public function performDisabledTransformation() {
		return $this->performReadonlyTransformation();
	}
}

/**
 * Readonly version of an {@link HTMLEditorField}.
 * @package forms
 * @subpackage fields-formattedinput
 */
class HtmlEditorField_Readonly extends ReadonlyField {
	function Field() {
		$valforInput = $this->value ? Convert::raw2att($this->value) : "";
		return "<span class=\"readonly typography\" id=\"" . $this->id() . "\">" . ( $this->value && $this->value != '<p></p>' ? $this->value : '<i>(not set)</i>' ) . "</span><input type=\"hidden\" name=\"".$this->name."\" value=\"".$valforInput."\" />";
	}
	function Type() {
		return 'htmleditorfield readonly';
	}
}

/**
 * External toolbar for the HtmlEditorField.
 * This is used by the CMS
 * @package forms
 * @subpackage fields-formattedinput
 */
class HtmlEditorField_Toolbar extends RequestHandler {

	static $allowed_actions = array(
		'LinkForm',
		'MediaForm',
		'browse',
	);

	protected $controller, $name;
	
	function __construct($controller, $name) {
		parent::__construct();

		Requirements::javascript(SAPPHIRE_DIR . "/thirdparty/jquery/jquery.js");
		Requirements::javascript(THIRDPARTY_DIR . '/jquery-ui/jquery-ui.js');
		Requirements::javascript(THIRDPARTY_DIR . '/jquery-entwine/dist/jquery.entwine-dist.js');
		Requirements::javascript(SAPPHIRE_ADMIN_DIR . '/javascript/ssui.core.js');
		Requirements::javascript(SAPPHIRE_DIR . "/thirdparty/behaviour/behaviour.js");
		Requirements::javascript(SAPPHIRE_DIR . "/javascript/tiny_mce_improvements.js");
		Requirements::javascript(SAPPHIRE_DIR ."/thirdparty/jquery-form/jquery.form.js");
		Requirements::javascript(SAPPHIRE_DIR ."/javascript/HtmlEditorField.js");

		Requirements::css(THIRDPARTY_DIR . '/jquery-ui-themes/smoothness/jquery-ui.css');
		
		$this->controller = $controller;
		$this->name = $name;
	}

	/**
	 * Searches the SiteTree for display in the dropdown
	 *  
	 * @return callback
	 */	
	function siteTreeSearchCallback($sourceObject, $labelField, $search) {
		return DataObject::get($sourceObject, "\"MenuTitle\" LIKE '%$search%' OR \"Title\" LIKE '%$search%'");
	}
	
	/**
	 * Return a {@link Form} instance allowing a user to
	 * add links in the TinyMCE content editor.
	 *  
	 * @return Form
	 */
	function LinkForm() {
		$siteTree = new TreeDropdownField('internal', _t('HtmlEditorField.PAGE', "Page"), 'SiteTree', 'ID', 'MenuTitle', true);
		// mimic the SiteTree::getMenuTitle(), which is bypassed when the search is performed
		$siteTree->setSearchFunction(array($this, 'siteTreeSearchCallback'));
		
		$numericLabelTmpl = '<span class="step-label"><span class="flyout">%d</span><span class="arrow"></span><strong class="title">%s</strong></span>';
		$form = new Form(
			$this->controller,
			"{$this->name}/LinkForm", 
			new FieldList(
				new LiteralField(
					'Heading', 
					sprintf('<h3>%s</h3>', _t('HtmlEditorField.LINK', 'Link'))
				),
				$contentComposite = new CompositeField(
					new OptionsetField(
						'LinkType',
						sprintf($numericLabelTmpl, '1', _t('HtmlEditorField.LINKTO', 'Link to')),
						array(
							'internal' => _t('HtmlEditorField.LINKINTERNAL', 'Page on the site'),
							'external' => _t('HtmlEditorField.LINKEXTERNAL', 'Another website'),
							'anchor' => _t('HtmlEditorField.LINKANCHOR', 'Anchor on this page'),
							'email' => _t('HtmlEditorField.LINKEMAIL', 'Email address'),
							'file' => _t('HtmlEditorField.LINKFILE', 'Download a file'),			
						)
					),
					new LiteralField('Step2',
						'<div class="step2">' . sprintf($numericLabelTmpl, '2', _t('HtmlEditorField.DETAILS', 'Details')) . '</div>'
					),
					$siteTree,
					new TextField('external', _t('HtmlEditorField.URL', 'URL'), 'http://'),
					new EmailField('email', _t('HtmlEditorField.EMAIL', 'Email address')),
					new TreeDropdownField('file', _t('HtmlEditorField.FILE', 'File'), 'File', 'Filename', 'Title', true),
					new TextField('Anchor', _t('HtmlEditorField.ANCHORVALUE', 'Anchor')),
					new TextField('Description', _t('HtmlEditorField.LINKDESCR', 'Link description')),
					new CheckboxField('TargetBlank', _t('HtmlEditorField.LINKOPENNEWWIN', 'Open link in a new window?')),
					new HiddenField('Locale', null, $this->controller->Locale)
				)
			),
			new FieldList(
				$removeAction = new ResetFormAction('remove', _t('HtmlEditorField.BUTTONREMOVELINK', 'Remove link')),
				$insertAction = new FormAction('insert', _t('HtmlEditorField.BUTTONINSERTLINK', 'Insert link'))
			)
		);
		
		$insertAction->addExtraClass('ss-ui-action-constructive');
		$removeAction->addExtraClass('ss-ui-action-destructive');
		$contentComposite->addExtraClass('content');
		
		$form->unsetValidator();
		$form->loadDataFrom($this);
		$form->addExtraClass('htmleditorfield-form htmleditorfield-linkform cms-dialog-content');
		
		$this->extend('updateLinkForm', $form);
		
		return $form;
	}

	/**
	 * Return a {@link Form} instance allowing a user to
	 * add images and flash objects to the TinyMCE content editor.
	 *  
	 * @return Form
	 */
	function MediaForm() {
		if(!class_exists('ThumbnailStripField')) {
			throw new Exception('ThumbnailStripField class required for HtmlEditorField->ImageForm()');
		}

		// TODO Handle through GridState within field - currently this state set too late to be useful here (during request handling)
		$parentID = $this->controller->getRequest()->requestVar('ParentID');

		$fileFieldConfig = GridFieldConfig::create();
		$fileFieldConfig->addComponent(new GridFieldSortableHeader());
		$fileFieldConfig->addComponent(new GridFieldFilter());
		$fileFieldConfig->addComponent(new GridFieldDefaultColumns());
		$fileFieldConfig->addComponent(new GridFieldPaginator(10));
		$fileField = new GridField('Files', false, false, $fileFieldConfig);
		$fileField->setList($this->getFiles($parentID));
		$fileField->setAttribute('data-selectable', true);
		$fileField->setAttribute('data-multiselect', true);
		
		
		$numericLabelTmpl = '<span class="step-label"><span class="flyout">%d</span><span class="arrow"></span><strong class="title">%s</strong></span>';
		$fields = new FieldList(
			new LiteralField(
				'Heading', 
				sprintf('<h3>%s</h3>', _t('HtmlEditorField.IMAGE', 'Image'))
			),
			
			$contentComposite = new CompositeField(
				new LiteralField('header1', '<h4 class="field">' . sprintf($numericLabelTmpl, '1', _t('HtmlEditorField.Find', 'Find')) . '</h4>'),
				new TreeDropdownField('ParentID', _t('HtmlEditorField.FOLDER', 'Folder'), 'Folder'),
				$fileField,

				new LiteralField('header2', '<h4 class="field edit-details">' . sprintf($numericLabelTmpl, '2', _t('HtmlEditorField.EditDetails', 'Edit details')) . '</h4>')
				// new TextField('AltText', _t('HtmlEditorField.IMAGEALTTEXT', 'Alternative text (alt) - shown if image cannot be displayed'), '', 80),
				// new TextField('ImageTitle', _t('HtmlEditorField.IMAGETITLE', 'Title text (tooltip) - for additional information about the image')),
				// new TextField('CaptionText', _t('HtmlEditorField.CAPTIONTEXT', 'Caption text')),
				// new DropdownField(
				// 	'CSSClass',
				// 	_t('HtmlEditorField.CSSCLASS', 'Alignment / style'),
				// 	array(
				// 		'left' => _t('HtmlEditorField.CSSCLASSLEFT', 'On the left, with text wrapping around.'),
				// 		'leftAlone' => _t('HtmlEditorField.CSSCLASSLEFTALONE', 'On the left, on its own.'),
				// 		'right' => _t('HtmlEditorField.CSSCLASSRIGHT', 'On the right, with text wrapping around.'),
				// 		'center' => _t('HtmlEditorField.CSSCLASSCENTER', 'Centered, on its own.'),
				// 	)
				// ),
				// new FieldGroup(_t('HtmlEditorField.IMAGEDIMENSIONS', 'Dimensions'),
				// 	new TextField('Width', _t('HtmlEditorField.IMAGEWIDTHPX', 'Width'), 100),
				// 	new TextField('Height', " x " . _t('HtmlEditorField.IMAGEHEIGHTPX', 'Height'), 100)
				// )
			)
		);
		
		$actions = new FieldList(
			$insertAction = new FormAction('insertimage', _t('HtmlEditorField.BUTTONINSERTIMAGE', 'Insert image'))
		);
		$insertAction->addExtraClass('ss-ui-action-constructive');

		$form = new Form(
			$this->controller,
			"{$this->name}/MediaForm",
			$fields,
			$actions
		);
		
		$contentComposite->addExtraClass('content');
		
		// Allow other people to extend the fields being added to the imageform 
		$this->extend('updateMediaForm', $form);
		
		$form->unsetValidator();
		$form->disableSecurityToken();
		$form->loadDataFrom($this);
		$form->addExtraClass('htmleditorfield-form htmleditorfield-mediaform cms-dialog-content');
		
		return $form;
	}

	public function browse($request) {
		
	}

	/**
	 * @param Int
	 * @return DataList
	 */
	protected function getFiles($parentID = null) {
		// TODO Use array('Filename:EndsWith' => $exts) once that's supported
		$exts = array('jpg', 'gif', 'png', 'swf');
		$wheres = array();
		foreach($exts as $ext) $wheres[] = '"Filename" LIKE \'%.' . $ext . '\'';

		$files = DataList::create('File')->where(implode(' OR ', $wheres));
		
		// Limit by folder (if required)
		if($parentID) $files->filter('ParentID', $parentID);
		
		return $files;
	}
}