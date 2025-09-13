<?php

import('lib.pkp.classes.plugins.GenericPlugin');

class GoogleScholarFixPlugin extends GenericPlugin
{
	/**
	 * @copydoc Plugin::register()
	 */
	function register($category, $path, $mainContextId = null)
	{
		if (parent::register($category, $path, $mainContextId)) {
			if ($this->getEnabled($mainContextId)) {
				HookRegistry::register('ArticleHandler::view', [$this, 'submissionView']);
			}
			return true;
		}
		return false;
	}

	function cleanAndInlineHtml(string $string): string
	{
		// 1. Remove all HTML tags from the string.
		// The strip_tags() function is a built-in PHP function that does this.
		$string = strip_tags($string);

		// 2. Replace non-breaking space entities (&nbsp;) with a regular space.
		// We use str_replace for a simple and fast replacement.
		$string = str_replace('&nbsp;', ' ', $string);

		// 3. Replace multiple whitespace characters (including newlines, tabs,
		// and multiple spaces) with a single space.
		// The regular expression `/\s+/` matches one or more whitespace characters.
		$string = preg_replace('/\s+/', ' ', $string);

		// 4. Trim leading and trailing whitespace from the final string.
		// This ensures there are no unnecessary spaces at the beginning or end.
		return trim($string);
	}

	/**
	 * Get the display name of this plugin
	 * @return string
	 */
	function getDisplayName()
	{
		return 'Google Scholar Fix Plugin';
	}

	/**
	 * Get the description of this plugin
	 * @return string
	 */
	function getDescription()
	{
		return 'Google Scholar Fix Plugin By Open Journal Theme';
	}

		/**
	 * Inject Google Scholar metadata into submission landing page view
	 * @param $hookName string
	 * @param $args array
	 * @return boolean
	 */
	function submissionView($hookName, $args) {
		$application = Application::get();
		$applicationName = $application->getName();
		$request = $args[0];
		$issue = $args[1];
		$submission = $args[2];
		$submissionPath = 'article';
		$requestArgs = $request->getRequestedArgs();
		$context = $request->getContext();

		// Only add Google Scholar metadata tags to the canonical URL for the latest version
		// See discussion: https://github.com/pkp/pkp-lib/issues/4870
		if (count($requestArgs) > 1 && $requestArgs[1] === 'version') {
			return;
		}

		$templateMgr = TemplateManager::getManager($request);

		// Context identification
		$templateMgr->addHeader('bepress_citation_journal_title', '<meta name="bepress_citation_journal_title" content="' . htmlspecialchars($context->getName($context->getPrimaryLocale())) . '"/>');
		if ( ($issn = $context->getData('onlineIssn')) || ($issn = $context->getData('printIssn')) || ($issn = $context->getData('issn'))) {
			$templateMgr->addHeader('bepress_citation_issn', '<meta name="bepress_citation_issn" content="' . htmlspecialchars($issn) . '"/> ');
		}

		// Contributors
		foreach ($submission->getAuthors() as $i => $author) {
			$templateMgr->addHeader('bepress_citation_author' . $i, '<meta name="bepress_citation_author" content="' . htmlspecialchars($author->getFullName(false)) .'"/>');

			if ($affiliation = htmlspecialchars($author->getLocalizedAffiliation() ?? '')) {
				$templateMgr->addHeader('bepress_citation_author_institution' . $i . 'Affiliation', '<meta name="bepress_citation_author_institution" content="' . $affiliation . '"/>');
			}
		}

		// Submission title
		$templateMgr->addHeader('bepress_citation_title', '<meta name="bepress_citation_title" content="' . htmlspecialchars($submission->getFullTitle($submission->getLocale())) . '"/>');
		// if ($locale = $submission->getLocale()) $templateMgr->addHeader('googleScholarLanguage', '<meta name="citation_language" content="' . htmlspecialchars(substr($locale, 0, 2)) . '"/>');

		// Submission publish date and issue information
		if (is_a($submission, 'Submission') && ($datePublished = $submission->getDatePublished()) && (!$issue || !$issue->getYear() || $issue->getYear() == strftime('%Y', strtotime($datePublished)))) {
			$templateMgr->addHeader('bepress_citation_date', '<meta name="bepress_citation_date" content="' . strftime('%Y/%m/%d', strtotime($datePublished)) . '"/>');
		} elseif ($issue && $issue->getYear()) {
			$templateMgr->addHeader('bepress_citation_date', '<meta name="bepress_citation_date" content="' . htmlspecialchars($issue->getYear()) . '"/>');
		} elseif ($issue && ($datePublished = $issue->getDatePublished())) {
			$templateMgr->addHeader('bepress_citation_date', '<meta name="bepress_citation_date" content="' . strftime('%Y/%m/%d', strtotime($datePublished)) . '"/>');
		}
		if ($issue) {
			if ($issue->getShowVolume()) $templateMgr->addHeader('bepress_citation_volume', '<meta name="bepress_citation_volume" content="' . htmlspecialchars($issue->getVolume()) . '"/>');
			if ($issue->getShowNumber()) $templateMgr->addHeader('bepress_citation_issue', '<meta name="bepress_citation_issue" content="' . htmlspecialchars($issue->getNumber()) . '"/>');
		}
		if ($submission->getPages()) {
			if ($startPage = $submission->getStartingPage()) $templateMgr->addHeader('bepress_citation_firstpage', '<meta name="bepress_citation_firstpage" content="' . htmlspecialchars($startPage) . '"/>');
			if ($endPage = $submission->getEndingPage()) $templateMgr->addHeader('bepress_citation_lastpage', '<meta name="bepress_citation_lastpage" content="' . htmlspecialchars($endPage) . '"/>');
		}

		// Identifiers: DOI, URN
		foreach((array) $templateMgr->getTemplateVars('pubIdPlugins') as $pubIdPlugin) {
			if ($pubId = $submission->getStoredPubId($pubIdPlugin->getPubIdType())) {
				$templateMgr->addHeader('bepress_citation_' . $pubIdPlugin->getPubIdDisplayType(), '<meta name="bepress_citation_' . htmlspecialchars(strtolower($pubIdPlugin->getPubIdDisplayType())) . '" content="' . htmlspecialchars($pubId) . '"/>');
			}
		}

		// Abstract url and keywords
		$templateMgr->addHeader('bepress_citation_abstract_html_url', '<meta name="bepress_citation_abstract_html_url" content="' . $request->url(null, $submissionPath, 'view', array($submission->getBestId())) . '"/>');

		$i=0;
		$dao = DAORegistry::getDAO('SubmissionKeywordDAO');
		$keywords = $dao->getKeywords($submission->getCurrentPublication()->getId(), array(AppLocale::getLocale()));
		foreach ($keywords as $locale => $localeKeywords) {
			foreach ($localeKeywords as $keyword) {
				// Note ini tidak ada di dokumentasi https://div.div1.com.au/div-thoughts/div-commentaries/66-div-commentary-metadata#_HTML-meta-tags-for-academic-publications
				$templateMgr->addHeader('bepress_citation_keywords' . $i++, '<meta name="bepress_citation_keywords" xml:lang="' . htmlspecialchars(substr($locale, 0, 2)) . '" content="' . htmlspecialchars($keyword) . '"/>');
			}
		}

		// Galley links
		$i=$j=0;
		if (is_a($submission, 'Submission')) foreach ($submission->getGalleys() as $galley) {
			if (is_a($galley->getFile(), 'SupplementaryFile')) continue;
			if ($galley->getFileType()=='application/pdf') {
				$templateMgr->addHeader('bepress_citation_pdf_url' . $i++, '<meta name="bepress_citation_pdf_url" content="' . $request->url(null, $submissionPath, 'download', array($submission->getBestId(), $galley->getBestGalleyId())) . '"/>');
			} elseif ($galley->getFileType()=='text/html') {
				$templateMgr->addHeader('bepress_citation_abstract_html_url' . $i++, '<meta name="bepress_citation_abstract_html_url" content="' . $request->url(null, $submissionPath, 'view', array($submission->getBestId(), $galley->getBestGalleyId())) . '"/>');
			}
		}

		// Citations
		// $outputReferences = [];
		// $citationDao = DAORegistry::getDAO('CitationDAO'); /* @var $citationDao CitationDAO */
		// $parsedCitations = $citationDao->getByPublicationId($submission->getCurrentPublication()->getId());
		// while ($citation = $parsedCitations->next()) {
		// 	$outputReferences[] = $citation->getRawCitation();
		// }
		// HookRegistry::call('GoogleScholarPlugin::references', array(&$outputReferences, $submission->getId()));

		// if (!empty($outputReferences)){
		// 	$i=0;
		// 	foreach ($outputReferences as $outputReference) {
		// 		// Note ini tidak ada di dokumentasi https://div.div1.com.au/div-thoughts/div-commentaries/66-div-commentary-metadata#_HTML-meta-tags-for-academic-publications
		// 		$templateMgr->addHeader('bepress_citation_abstract_html_url' . $i++, '<meta name="bepress_citation_reference" content="' . htmlspecialchars($outputReference) . '"/>');
		// 	}
		// }

		return false;
	}

}
