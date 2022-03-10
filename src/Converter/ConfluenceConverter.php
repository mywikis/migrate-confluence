<?php

namespace HalloWelt\MigrateConfluence\Converter;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use HalloWelt\MediaWiki\Lib\Migration\Converter\PandocHTML;
use HalloWelt\MediaWiki\Lib\Migration\DataBuckets;
use HalloWelt\MediaWiki\Lib\Migration\IOutputAwareInterface;
use HalloWelt\MediaWiki\Lib\Migration\Workspace;
use HalloWelt\MigrateConfluence\Converter\ConvertableEntities\Emoticon;
use HalloWelt\MigrateConfluence\Converter\ConvertableEntities\Image;
use HalloWelt\MigrateConfluence\Converter\ConvertableEntities\Link;
use HalloWelt\MigrateConfluence\Converter\ConvertableEntities\Macros\Code;
use HalloWelt\MigrateConfluence\Converter\Postprocessor\FixLineBreakInHeadings;
use HalloWelt\MigrateConfluence\Converter\Postprocessor\RestoreTableAttributes;
use HalloWelt\MigrateConfluence\Converter\Preprocessor\CDATAClosingFixer;
use HalloWelt\MigrateConfluence\Converter\Processor\ConvertInfoMacro;
use HalloWelt\MigrateConfluence\Converter\Processor\ConvertNoteMacro;
use HalloWelt\MigrateConfluence\Converter\Processor\ConvertStatusMacro;
use HalloWelt\MigrateConfluence\Converter\Processor\ConvertTipMacro;
use HalloWelt\MigrateConfluence\Converter\Processor\ConvertWarningMacro;
use HalloWelt\MigrateConfluence\Converter\Processor\PreserveTableAttributes;
use HalloWelt\MigrateConfluence\Converter\Processor\StructuredMacroColumn;
use HalloWelt\MigrateConfluence\Converter\Processor\StructuredMacroPanel;
use HalloWelt\MigrateConfluence\Converter\Processor\StructuredMacroSection;
use HalloWelt\MigrateConfluence\Utility\ConversionDataLookup;
use SplFileInfo;
use Symfony\Component\Console\Output\Output;

class ConfluenceConverter extends PandocHTML implements IOutputAwareInterface {

	protected $bodyContentFile = null;

	/**
	 * @var DataBuckets
	 */
	private $dataBuckets = null;

	/**
	 *
	 * @var ConversionDataLookup
	 */
	private $dataLookup = null;

	/**
	 *
	 * @var SplFileInfo
	 */
	private $rawFile = null;

	/**
	 * @var string
	 */
	private $wikiText = '';

	/**
	 * @var string
	 */
	private $currentPageTitle = '';

	private $currentSpace = 0;

	/**
	 *
	 * @var SplFileInfo
	 */
	private $preprocessedFile = null;

	/**
	 * @var Output
	 */
	private $output = null;

	/**
	 *
	 * @param array $config
	 * @param Workspace $workspace
	 */
	public function __construct( $config, Workspace $workspace ) {
		parent::__construct( $config, $workspace );

		$this->dataBuckets = new DataBuckets( [
			'pages-ids-to-titles-map',
			'pages-titles-map',
			'title-attachments',
			'body-contents-to-pages-map',
			'page-id-to-space-id',
			'space-id-to-prefix-map',
			'filenames-to-filetitles-map',
			'title-metadata',
			'attachment-orig-filename-target-filename-map',
			'files'
		] );

		$this->dataBuckets->loadFromWorkspace( $this->workspace );
	}

	/**
	 * @param Output $output
	 */
	public function setOutput( Output $output ) {
		$this->output = $output;
	}

	/**
	 * @inheritDoc
	 */
	protected function doConvert( SplFileInfo $file ): string {
		$this->output->writeln( $file->getPathname() );
		$this->dataLookup = ConversionDataLookup::newFromBuckets( $this->dataBuckets );
		$this->rawFile = $file;

		$bodyContentId = $this->getBodyContentIdFromFilename();
		$pageId = $this->getPageIdFromBodyContentId( $bodyContentId );
		if ( $pageId === -1 ) {
			return '<-- No context page id found -->';
		}
		$this->currentSpace = $this->getSpaceIdFromPageId( $pageId );

		$pagesIdsToTitlesMap = $this->dataBuckets->getBucketData( 'pages-ids-to-titles-map' );
		if ( isset( $pagesIdsToTitlesMap[$pageId] ) ) {
			$this->currentPageTitle = $pagesIdsToTitlesMap[$pageId];
		} else {
			$this->currentPageTitle = 'not_current_revision_' . $pageId;
		}

		$dom = $this->preprocessFile();

		$xpath = new DOMXPath( $dom );
		$xpath->registerNamespace( 'ac', 'some' );
		$xpath->registerNamespace( 'ri', 'thing' );
		$replacings = $this->makeReplacings();
		foreach ( $replacings as $xpathQuery => $callback ) {
			$matches = $xpath->query( $xpathQuery );
			$nonLiveListMatches = [];
			foreach ( $matches as $match ) {
				$nonLiveListMatches[] = $match;
			}
			foreach ( $nonLiveListMatches as $match ) {
				//phpcs:ignore Generic.Files.LineLength.TooLong
				// See: https://wiki.hallowelt.com/index.php/Technik/Migration/Confluence_nach_MediaWiki#Inhalte
				//phpcs:ignore Generic.Files.LineLength.TooLong
				// See: https://confluence.atlassian.com/doc/confluence-storage-format-790796544.html
				call_user_func_array(
					$callback,
					[ $this, $match, $dom, $xpath ]
				);
			}
		}

		$this->runProcessors( $dom );
		$this->postProcessDOM( $dom, $xpath );

		$dom->saveHTMLFile(
			$this->preprocessedFile->getPathname()
		);

		$this->wikiText = parent::doConvert( $this->preprocessedFile );
		$this->runPostProcessors();

		$this->postProcessLinks();
		$this->postprocessWikiText();
		return $this->wikiText;
	}

	/**
	 *
	 * @param DOMDocument $dom
	 * @return void
	 */
	private function runProcessors( $dom ) {
		$processors = [
			new PreserveTableAttributes(),
			new ConvertTipMacro(),
			new ConvertInfoMacro(),
			new ConvertNoteMacro(),
			new ConvertWarningMacro(),
			new ConvertStatusMacro(),
			new StructuredMacroPanel(),
			new StructuredMacroColumn(),
			new StructuredMacroSection()
		];

		/** @var IProcessor $processor */
		foreach ( $processors as $processor ) {
			$processor->process( $dom );
		}
	}

	/**
	 *
	 * @return void
	 */
	private function runPostProcessors() {
		$postProcessors = [
			new RestoreTableAttributes(),
			new FixLineBreakInHeadings()
		];

		/** @var IPostprocessor $postProcessor */
		foreach ( $postProcessors as $postProcessor ) {
			$this->wikiText = $postProcessor->postprocess( $this->wikiText );
		}
	}

	/**
	 *
	 * @return int
	 */
	private function getBodyContentIdFromFilename() {
		// e.g. "67856345.mraw"
		$filename = $this->rawFile->getFilename();
		$filenameParts = explode( '.', $filename, 2 );
		return (int)$filenameParts[0];
	}

	/**
	 *
	 * @param int $bodyContentId
	 * @return int
	 */
	private function getPageIdFromBodyContentId( $bodyContentId ) {
		$map = $this->dataBuckets->getBucketData( 'body-contents-to-pages-map' );
		return $map[$bodyContentId] ?? -1;
	}

	/**
	 *
	 * @param int $pageId
	 * @return int
	 */
	private function getSpaceIdFromPageId( $pageId ) {
		$map = $this->dataBuckets->getBucketData( 'page-id-to-space-id' );
		return $map[$pageId] ?? -1;
	}

	/**
	 *
	 * @return void
	 */
	private function preprocessFile() {
		$source = $this->preprocessHTMLSource( $this->rawFile );
		$dom = new DOMDocument();
		$dom->recover = true;
		$dom->formatOutput = true;
		$dom->preserveWhiteSpace = true;
		$dom->validateOnParse = false;
		$dom->loadXML( $source, LIBXML_PARSEHUGE );

		$preprocessedPathname = str_replace( '.mraw', '.mprep', $this->rawFile->getPathname() );
		$dom->saveHTMLFile( $preprocessedPathname );
		$this->preprocessedFile = new SplFileInfo( $preprocessedPathname );

		return $dom;
	}

	/**
	 * @param ConfluenceConverter $sender
	 * @param DOMElement $match
	 * @param DOMDocument $dom
	 * @param DOMXPath $xpath
	 */
	private function processStructuredMacro( $sender, $match, $dom, $xpath ) {
		$this->processMacro( $sender, $match, $dom, $xpath );
	}

	/**
	 * @param ConfluenceConverter $sender
	 * @param DOMElement $match
	 * @param DOMDocument $dom
	 * @param DOMXPath $xpath
	 */
	private function processMacro( $sender, $match, $dom, $xpath ) {
		$replacement = '';
		$sMacroName = $match->getAttribute( 'ac:name' );

		// Exclude macros that are handled by an `IProcessor`
		if ( in_array( $sMacroName, [ 'info', 'note', 'tip', 'warning', 'status', 'panel', 'section', 'column' ] ) ) {
			return;
		}

		if ( $sMacroName === 'gliffy' ) {
			$this->processGliffyMacro( $sender, $match, $dom, $xpath, $replacement );
		} elseif ( $sMacroName === 'localtabgroup' || $sMacroName === 'localtab' ) {
			$this->processLocalTabMacro( $sender, $match, $dom, $xpath, $replacement, $sMacroName );
		} elseif ( $sMacroName === 'excerpt' ) {
			$this->processExcerptMacro( $sender, $match, $dom, $xpath, $replacement );
		} elseif ( $sMacroName === 'code' ) {
			$processor = new Code();
			$processor->process( $sender, $match, $dom, $xpath );
		} elseif ( $sMacroName === 'viewdoc' || $sMacroName === 'viewxls' || $sMacroName === 'viewpdf' ) {
			$this->processViewXMacro( $sender, $match, $dom, $xpath, $replacement, $sMacroName );
		} elseif ( $sMacroName === 'children' ) {
			$this->processChildrenMacro( $sender, $match, $dom, $xpath, $replacement );
		} elseif ( $sMacroName === 'widget' ) {
			$this->processWidgetMacro( $sender, $match, $dom, $xpath, $replacement );
		} elseif ( $sMacroName === 'recently-updated' ) {
			$this->processRecentlyUpdatedMacro( $sender, $match, $dom, $xpath, $replacement );
		} elseif ( $sMacroName === 'tasklist' ) {
			$this->processTaskListMacro( $sender, $match, $dom, $xpath, $replacement );
		} elseif ( $sMacroName === 'toc' ) {
			$replacement = "\n__TOC__\n###BREAK###";
		} else {
			// TODO: 'calendar', 'contributors', 'anchor',
			// 'pagetree', 'navitabs', 'include', 'listlabels', 'content-report-table'
			$this->logMarkup( $match );
			$replacement .= "[[Category:Broken_macro/$sMacroName]]";
		}

		$parentNode = $match->parentNode;
		if ( $parentNode === null ) {
			return;
		}
		$parentNode->replaceChild(
			$dom->createTextNode( $replacement ),
			$match
		);
	}

	/**
	 *
	 * @param DOMNode $oNode
	 */
	protected function logMarkup( $oNode ) {
		if ( $oNode instanceof DOMElement === false ) {
			return;
		}

		error_log(
			"NODE: \n" .
			$oNode->ownerDocument->saveXML( $oNode )
		);
	}

	/**
	 *
	 * @param ConfluenceConverter $sender
	 * @param DOMElement $match
	 * @param DOMDocument $dom
	 * @param DOMXPath $xpath
	 * @return void
	 */
	protected function processPlaceholder( $sender, $match, $dom, $xpath ) {
		$replacement = $match->textContent;

		if ( !empty( $replacement ) ) {
			$replacement = '<!--' . $replacement . '-->';

			$match->parentNode->replaceChild(
				$dom->createTextNode( $replacement ),
				$match
			);
		}
	}

	/**
	 *
	 * @return array
	 */
	private function makeReplacings() {
		$spaceIdPrefixMap = $this->dataBuckets->getBucketData( 'space-id-to-prefix-map' );
		$prefix = $spaceIdPrefixMap[$this->currentSpace];
		$currentPageTitle = $this->currentPageTitle;
		if ( substr( $currentPageTitle, 0, strlen( "$prefix:" ) ) === "$prefix:" ) {
			$currentPageTitle = str_replace( "$prefix:", '', $currentPageTitle );
		}

		return [
			'//ac:link' => [ new Link( $this->dataLookup, $this->currentSpace, $currentPageTitle ), 'process' ],
			'//ac:image' => [ new Image( $this->dataLookup, $this->currentSpace, $currentPageTitle ), 'process' ],
			'//ac:macro' => [ $this, 'processMacro' ],
			'//ac:structured-macro' => [ $this, 'processStructuredMacro' ],
			'//ac:emoticon' => [ new Emoticon(), 'process' ],
			'//ac:task-list' => [ $this, 'processTaskList' ],
			'//ac:inline-comment-marker' => [ $this, 'processInlineCommentMarker' ],
			'//ac:placeholder' => [ $this, 'processPlaceholder' ]
		];
	}

	/**
	 *
	 * @param SplFileInfo $oHTMLSourceFile
	 * @return string
	 */
	protected function preprocessHTMLSource( $oHTMLSourceFile ) {
		$sContent = file_get_contents( $oHTMLSourceFile->getPathname() );
		$bodyContentId = $this->getBodyContentIdFromFilename( $oHTMLSourceFile->getFilename() );
		$pageId = $this->getPageIdFromBodyContentId( $bodyContentId );

		$preprocessors = [
			new CDATAClosingFixer()
		];
		/** @var IPreprocessor $preprocessor */
		foreach ( $preprocessors as $preprocessor ) {
			$sContent = $preprocessor->preprocess( $sContent );
		}

		/**
		 * As this is a mixture of XML and HTML the XMLParser has trouble
		 * with entities from HTML. To circumvent this we replace all entites
		 * by their literal. A better solution would be to make the entities
		 * known to the XMLParser, e.g. by using a DTD.
		 * This is something for a future development...
		 */
		$aReplaces = array_flip( get_html_translation_table( HTML_ENTITIES ) );
		unset( $aReplaces['&amp;'] );
		unset( $aReplaces['&lt;'] );
		unset( $aReplaces['&gt;'] );
		unset( $aReplaces['&quot;'] );
		foreach ( $aReplaces as $sEntity => $replacement ) {
			$sContent = str_replace( $sEntity, $replacement, $sContent );
		}

		// For now we just replace the layout markup of Confluence with simple
		// HTML div markup
		$sContent = str_replace( '<ac:layout-section', '{{Layout}}<ac:layout-section', $sContent );
		// "ac:layout-section" is the only one with a "ac:type" attribute
		// phpcs:ignore Generic.Files.LineLength.TooLong
		$sContent = str_replace( '<ac:layout-section ac:type="', '<div class="ac-layout-section ', $sContent );
		// phpcs:ignore Generic.Files.LineLength.TooLong
		$sContent = str_replace( '<ac:layout-section', '<div class="ac-layout-section"', $sContent );
		$sContent = str_replace( '</ac:layout-section', '</div', $sContent );

		$sContent = str_replace( '<ac:layout-cell', '<div class="ac-layout-cell"', $sContent );
		$sContent = str_replace( '</ac:layout-cell', '</div', $sContent );

		$sContent = str_replace( '<ac:layout', '<div class="ac-layout"', $sContent );
		$sContent = str_replace( '</ac:layout', '</div', $sContent );

		// Append categories
		$categorieMap = $this->dataBuckets->getBucketData( 'title-metadata' );
		$categories = '';
		if ( isset( $categorieMap[$pageId] ) && isset( $categorieMap[$pageId]['categories'] ) ) {
			foreach ( $categorieMap[$pageId]['categories'] as $key => $category ) {
				$category = ucfirst( $category );
				$categories .= "[[Category:$category]]\n";
			}
		}
		$sContent = str_replace( '</body>', $categories . '</body>', $sContent );

		// phpcs:ignore Generic.Files.LineLength.TooLong
		$sContent = '<xml xmlns:ac="some" xmlns:ri="thing" xmlns:bs="bluespice">' . $sContent . '</xml>';

		return $sContent;
	}

	/**
	 *
	 * <ac::macro ac:name="localtabgroup">
	 * <ac::rich-text-body>
	 * <ac::macro ac:name="localtab">
	 * <ac::parameter ac:name="title">...</acparameter>
	 * <ac::rich-text-body>...</acrich-text-body>
	 * </ac:macro>
	 * </ac:rich-text-body>
	 * </ac:macro>
	 * @param ConfluenceConverter $sender
	 * @param DOMElement $match
	 * @param DOMDocument $dom
	 * @param DOMXPath $xpath
	 * @param string &$replacement
	 * @param string $sMacroName
	 */
	private function processLocalTabMacro( $sender, $match, $dom, $xpath, &$replacement, $sMacroName ) {
		if ( $sMacroName === 'localtabgroup' ) {
			// Append the "<headertabs />" tag
			$match->parentNode->appendChild(
				$dom->createTextNode( '<headertabs />' )
			);
		} elseif ( $sMacroName === 'localtab' ) {
			$oTitleParam = $xpath->query( './ac:parameter[@ac:name="title"]', $match )->item( 0 );
			// Prepend the heading
			$match->parentNode->insertBefore(
				$dom->createElement( 'h1', $oTitleParam->nodeValue ),
				$match
			);
		}

		$oRTBody = $xpath->query( './ac:rich-text-body', $match )->item( 0 );
		// Move all content out of <ac:rich-text-body>
		while ( $oRTBody->childNodes->length > 0 ) {
			$oChild = $oRTBody->childNodes->item( 0 );
			$match->parentNode->insertBefore( $oChild, $match );
		}
	}

	/**
	 * @param ConfluenceConverter $sender
	 * @param DOMElement $match
	 * @param DOMDocument $dom
	 * @param DOMXPath $xpath
	 * @param string &$replacement
	 */
	private function processExcerptMacro( $sender, $match, $dom, $xpath, &$replacement ) {
		$oNewContainer = $dom->createElement( 'div' );
		$oNewContainer->setAttribute( 'class', 'ac-excerpt' );

		// TODO: reflect modes "INLINE" and "BLOCK"
		//See https://confluence.atlassian.com/doc/excerpt-macro-148062.html

		$match->parentNode->insertBefore( $oNewContainer, $match );

		$oRTBody = $xpath->query( './ac:rich-text-body', $match )->item( 0 );
		// Move all content out of <ac::rich-text-body>
		while ( $oRTBody->childNodes->length > 0 ) {
			$oChild = $oRTBody->childNodes->item( 0 );
			$oNewContainer->appendChild( $oChild );
		}
	}

	/**
	 * @param ConfluenceConverter $sender
	 * @param DOMElement $match
	 * @param DOMDocument $dom
	 * @param DOMXPath $xpath
	 * @param string &$replacement
	 * @param string $sMacroName
	 */
	private function processViewXMacro( $sender, $match, $dom, $xpath, &$replacement, $sMacroName ) {
		$oNameParam = $xpath->query( './ac:parameter[@ac:name="name"]', $match )->item( 0 );
		$oRIAttachmentEl = $xpath->query( './ac:parameter/ri:attachment', $match )->item( 0 );
		if ( $oNameParam instanceof DOMElement ) {
			$sTargetFile = $oNameParam->nodeValue;
			// Sometimes the target is not the direct nodeValue but an
			//atttribute value of a child <ri::attachment> element
			if ( empty( $sTargetFile ) && $oRIAttachmentEl instanceof DOMElement ) {
				$sTargetFile = $oRIAttachmentEl->getAttribute( 'ri:filename' );
			}

			$spaceIdPrefixMap = $this->dataBuckets->getBucketData( 'space-id-to-prefix-map' );
			$prefix = $spaceIdPrefixMap[$this->currentSpace];
			$currentPageTitle = $this->currentPageTitle;
			if ( substr( $currentPageTitle, 0, strlen( "$prefix:" ) ) === "$prefix:" ) {
				$currentPageTitle = str_replace( "$prefix:", '', $currentPageTitle );
			}

			$linkConverter = new Link( $this->dataLookup, $this->currentSpace, $currentPageTitle );

			$oContainer = $dom->createElement(
				'span',
				$linkConverter->makeMediaLink( [ $sTargetFile ] )
			);
			$oContainer->setAttribute( 'class', "ac-$sMacroName" );
			$match->parentNode->insertBefore( $oContainer, $match );
		}
	}

	/**
	 * @param ConfluenceConverter $sender
	 * @param DOMElement $match
	 * @param DOMDocument $dom
	 * @param DOMXPath $xpath
	 * @param string &$replacement
	 */
	private function processChildrenMacro( $sender, $match, $dom, $xpath, &$replacement ) {
		$iDepth = 1;
		// Looking for<ac:parameter ac:name="depth">2</ac:parameter>
		$oDepthParam = $xpath->query( './ac:parameter[@ac:name="depth"]', $match )->item( 0 );
		if ( $oDepthParam instanceof DOMNode ) {
			$iDepth = (int)$oDepthParam->nodeValue;
		}

		// https://github.com/JeroenDeDauw/SubPageList/blob/master/doc/USAGE.md
		$oElement = $match->ownerDocument->createElement( 'div' );
		$oElement->setAttribute( 'class', 'subpagelist subpagelist-depth-' . $iDepth );
		$oElement->appendChild(
			$match->ownerDocument->createTextNode(
				'{{SubpageList|page=' . $this->currentPageTitle . '|depth=' . $iDepth . '}}'
			)
		);
		$match->parentNode->insertBefore( $oElement, $match );
	}

	/**
	 * @param ConfluenceConverter $sender
	 * @param DOMElement $match
	 * @param DOMDocument $dom
	 * @param DOMXPath $xpath
	 * @param string &$replacement
	 */
	private function processGliffyMacro( $sender, $match, $dom, $xpath, &$replacement ) {
		$oNameParam = $xpath->query( './ac:parameter[@ac:name="name"]', $match )->item( 0 );
		if ( !empty( $oNameParam->nodeValue ) ) {
			$imgConverter = new Image( $this->dataLookup, $this->currentSpace, $this->currentPageTitle );
			$replacementNode = $imgConverter->makeImageLink( $dom, [ "{$oNameParam->nodeValue}.png" ] );
			$replacement = $replacementNode->ownerDocument->saveXML( $replacementNode );
		}
	}

	/**
	 * @param ConfluenceConverter $sender
	 * @param DOMElement $match
	 * @param DOMDocument $dom
	 * @param DOMXPath $xpath
	 * @param string &$replacement
	 */
	private function processWidgetMacro( $sender, $match, $dom, $xpath, &$replacement ) {
		$oParamEls = $xpath->query( './ac:parameter', $match );
		$params = [
			'url' => ''
		];
		foreach ( $oParamEls as $oParamEl ) {
			$params[$oParamEl->getAttribute( 'ac:name' )] = $oParamEl->nodeValue;
		}
		$oContainer = $dom->createElement( 'div', $params['url'] );
		$oContainer->setAttribute( 'class', "ac-widget" );
		$oContainer->setAttribute( 'data-params', json_encode( $params ) );
		$match->parentNode->insertBefore( $oContainer, $match );
	}

	/**
	 * @param ConfluenceConverter $sender
	 * @param DOMElement $match
	 * @param DOMDocument $dom
	 * @param DOMXPath $xpath
	 * @param string &$replacement
	 */
	private function processTaskListMacro( $sender, $match, $dom, $xpath, &$replacement ) {
		$this->processTaskList( $sender, $match, $dom, $xpath );
	}

	/**
	 *
	 * <ac:task-list>
	 * <ac:task>
	 * <ac:task-id>29</ac:task-id>
	 * <ac:task-status>incomplete</ac:task-status>
	 * <ac:task-body><strong>Edit this home page</strong> - Click <em>Edit</em> ...</ac:task-body>
	 * </ac:task>
	 * <ac:task>
	 * <ac:task-id>30</ac:task-id>
	 * <ac:task-status>incomplete</ac:task-status>
	 * <ac:task-body><strong>Create your first page</strong> - Click the <em>Create</em> ...</ac:task-body>
	 * <ac:task>
	 * </ac:task-list>
	 * @param ConfluenceConverter $sender
	 * @param DOMElement $match
	 * @param DOMDocument $dom
	 * @param DOMXPath $xpath
	 */
	private function processTaskList( $sender, $match, $dom, $xpath ) {
		$wikiText = [];
		$tasks = $match->getElementsByTagName( 'task' );

		$wikiText[] = '{{TaskListStart}}###BREAK###';
		foreach ( $tasks as $task ) {
			$elId = $task->getElementsByTagName( 'task-id' )->item( 0 );
			$elStatus = $task->getElementsByTagName( 'task-status' )->item( 0 );
			$elBody = $task->getElementsByTagName( 'task-body' )->item( 0 );

			$id = $elId instanceof DOMElement ? $elId->nodeValue : -1;
			$status = $elStatus instanceof DOMElement ? $elStatus->nodeValue : '';
			$body = $elBody instanceof DOMElement ? $dom->saveXML( $elBody ) : '';
			$body = str_replace( [ '<ac:task-body>', '</ac:task-body>' ], '', $body );

			$wikiText[] = <<<HERE
{{Task###BREAK###
 | id = $id###BREAK###
 | status = $status###BREAK###
 | body = $body###BREAK###
}}###BREAK###
HERE;
		}
		$wikiText[] = '{{TaskListEnd}}###BREAK###';
		$wikiText = implode( "\n", $wikiText );

		$match->parentNode->replaceChild(
			$dom->createTextNode( $wikiText ),
			$match
		);
	}

	/**
	 * <ac:inline-comment-marker ac:ref="ca3f84d8-5618-4cdb-b8f6-b58f4e29864e">
	 *	Alternatives
	 * </ac:inline-comment-marker>
	 * @param ConfluenceConverter $sender
	 * @param DOMElement $match
	 * @param DOMDocument $dom
	 * @param DOMXPath $xpath
	 */
	private function processInlineCommentMarker( $sender, $match, $dom, $xpath ) {
		$wikiText = "{{InlineComment|{$match->nodeValue}}}";
		$match->parentNode->replaceChild(
			$dom->createTextNode( $wikiText ),
			$match
		);
	}

	/**
	 *
	 * @param DOMDocument $dom
	 * @param DOMXPath $xpath
	 */
	public function postProcessDOM( $dom, $xpath ) {
		/*
		 * BlueSpice VisualEditor breaks on <div>'s with data attributes
		 * containing JSON
		 */
		$oElementsWithDataAttr = $xpath->query( '//*[@data-atlassian-layout]' );
		foreach ( $oElementsWithDataAttr as $oElementWithDataAttr ) {
			$oElementWithDataAttr->setAttribute( 'data-atlassian-layout', null );
		}
	}

	/**
	 *
	 * @return void
	 */
	public function postProcessLinks() {
		$oldToNewTitlesMap = $this->dataBuckets->getBucketData( 'pages-titles-map' );

		$this->wikiText = preg_replace_callback(
			"/\[\[Media:(.*)]]/",
			function ( $matches ) use( $oldToNewTitlesMap ) {
				if ( isset( $oldToNewTitlesMap[$matches[1]] ) ) {
					return $oldToNewTitlesMap[$matches[1]];
				}
				return $matches[0];
			},
			$this->wikiText
		);

		// Pandoc converts external images like <img src='...' /> among others. It is not correct.
		// So we need to find all images which look like that [[File:https://somesite.com]] and
		// convert them back to <img>.
		$this->wikiText = preg_replace_callback(
			"/\[\[File:(http[s]?:\/\/.*)]]/",
			function ( $matches ) {
				if ( strpos( $matches[1], '|' ) ) {
					$params = explode( '|', $matches[1] );
					$replacement = $params[0];
				} else {
					$replacement = $matches[1];
				}

				if ( parse_url( $matches[1] ) ) {
					return "<img src='$replacement' />";
				}
				return $matches[0];
			},
			$this->wikiText
		);
	}

	/**
	 * @param ConfluenceConverter $sender
	 * @param DOMElement $match
	 * @param DOMDocument $dom
	 * @param DOMXPath $xpath
	 * @param string &$replacement
	 */
	private function processRecentlyUpdatedMacro( $sender, $match, $dom, $xpath, &$replacement ) {
		$sNsText = '';
		$aTitleParts = explode( ':', $this->currentPageTitle, 2 );
		if ( count( $aTitleParts ) === 2 ) {
			$sNsText = $aTitleParts[0];
		}
		$replacement = sprintf(
			'{{RecentlyUpdated|namespace=%s}}',
			$sNsText
		);
	}

	/**
	 *
	 * @return void
	 */
	private function postprocessWikiText() {
		// On Windows the CR would be encoded as "&#xD;" in the MediaWiki-XML, which is ulgy and unnecessary
		$this->wikiText = str_replace( "\r", '', $this->wikiText );
		$this->wikiText = str_replace( "###BREAK###", "\n", $this->wikiText );
		$this->wikiText = str_replace( "\n {{", "\n{{", $this->wikiText );
		$this->wikiText = str_replace( "\n }}", "\n}}", $this->wikiText );
		$this->wikiText = str_replace( "\n- ", "\n* ", $this->wikiText );
		$this->wikiText = preg_replace_callback(
			[
				// This is just for "TaskList", as it will add XML as TextNode.
				// It should be removed as soon as TaskList is properly converted.
				"#&lt;span.*?&gt;#si",
				"#&lt;/span&gt;#si",
				"#&lt;div.*?&gt;#si",
				"#&lt;/div&gt;#si",
				// End TaskList specific

				"#&lt;headertabs /&gt;#si",
				"#&lt;subpages(.*?)/&gt;#si",
				"#&lt;img(.*?)/&gt;#s"
			],
			function ( $aMatches ) {
				return html_entity_decode( $aMatches[0] );
			},
			$this->wikiText
		);

		$attachmentsMap = $this->dataBuckets->getBucketData( 'title-attachments' );
		if ( isset( $attachmentsMap[$this->currentPageTitle] ) ) {
			$mediaExludeList = $this->buildMediaExcludeList( $this->wikiText );
			$attachmentList = [];
			foreach ( $attachmentsMap[$this->currentPageTitle] as $attachment ) {
				if ( in_array( $attachment, $mediaExludeList ) ) {
					continue;
				}
				$attachmentList[] = $attachment;
			}

			if ( !empty( $attachmentList ) ) {
				$this->wikiText .= "\n{{AttachmentsSectionStart}}\n";
				foreach ( $attachmentList as $attachment ) {
					$this->wikiText .= "* [[Media:$attachment]]\n";
				}
				$this->wikiText .= "\n{{AttachmentsSectionEnd}}\n";
			}
		}

		$this->wikiText .= "\n <!-- From bodyContent {$this->rawFile->getBasename()} -->";
	}

	/**
	 *
	 * @param string $wikiText
	 * @return array
	 */
	private function buildMediaExcludeList( $wikiText ): array {
		$excludes = [ 'File', 'Media' ];

		$matches = [];
		$excludes = implode( '|', $excludes );
		preg_match_all( "#\[\[\s*(File|Media):(.*?)\s*[\|*|\]\]]#im", $wikiText, $matches );
		$exludeList = [];
		foreach ( $matches[2] as $match ) {
			$exludeList[] = $match;
		}

		return $exludeList;
	}
}
