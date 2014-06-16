<?php

class Backup {

    const LOG_FILE = 'userscriptsBackup.log';
    const SAVE_DIR = 'userscripts.org.bak';
    const LAST_PAGE_FILE = 'lastpage.txt';
    const LOCAL_PATH_LIST = 'list-%d.html';
    const HEAVY_LOAD_PAGE_SIZE = 177;
    const USERSCRIPTS_DOMAIN = 'userscripts.org';
    const USERSCRIPTS_PORT = 8080;
    const USERSCRIPTS_PATH_LIST = '/scripts?sort=id&page=%d';
    const USERSCRIPTS_PATH_SCRIPT_SOURCE = '/scripts/source/%d.user.js';
    const USERSCRIPTS_PATH_SCRIPT_PAGE = '/scripts/show/%d';

    public function go() {
        $listPageExists = true;
        $pageNum = 1;
        $lastPageFile = self::SAVE_DIR . '/' . self::LAST_PAGE_FILE;
        if (file_exists($lastPageFile)) {
            $pageNum = (integer) file_get_contents($lastPageFile);
        }
        // Getting first page to know how many pages are there
        $listPagePathFormat = 'http://%s:%d' . self::USERSCRIPTS_PATH_LIST;
        $listPagePath = sprintf(
            $listPagePathFormat,
            self::USERSCRIPTS_DOMAIN,
            self::USERSCRIPTS_PORT,
            $pageNum
        );
        $urls = array();
        $urls[] = $listPagePath;
        $this->log('Downloading reference list page #' . $pageNum);
        $content = $this->curlMultiGet($urls);
        // Parsing the list page
        $this->log('Parsing reference list page #' . $pageNum);
        $dom = new DOMDocument();
        if (@$dom->loadHTML($content[$listPagePath]) === false) {
            $this->log('Error parsing reference list page #' . $pageNum);
            return;
        }
        $pageNums = array();
        foreach ($dom->getElementsByTagName('div') as $div) {
            if ($div->getAttribute('class') != 'pagination') {
                continue;
            }
            foreach ($div->getElementsByTagName('a') as $a) {
                $pageNums[] = (integer) $a->nodeValue;
            }
            foreach ($div->getElementsByTagName('span') as $span) {
                if ($span->getAttribute('class') != 'current') {
                    continue;
                }
                $pageNums[] = (integer) $span->nodeValue;
            }
        }
        $maxNum = max($pageNums);
        $this->log('There are ' . $maxNum . ' list pages');
        // Downloading list pages
        $urls = array();
        for ($i = $pageNum; $i <= $maxNum; $i++) {
            $localListPagePath = sprintf(self::LOCAL_PATH_LIST, $i);
            if (!file_exists($localListPagePath)
                || filesize($localListPagePath) < self::HEAVY_LOAD_PAGE_SIZE
                || strpos(file_get_contents($localListPagePath), '</html>') === false
            ) {
                $urls[$i] = sprintf(
                    $listPagePathFormat,
                    self::USERSCRIPTS_DOMAIN,
                    self::USERSCRIPTS_PORT,
                    $i
                );
            }
            if (count($urls) == 50 || ($i == $maxNum && count($urls) > 0)) {
                // A batch is ready to be downloaded
                $this->log('Downloading list pages ' . min(array_keys($urls))
                    . ' to ' . max(array_keys($urls))
                );
                $content = $this->curlMultiGet($urls);
                $urlsToIds = array_flip($urls);
                $unfinishedUrls = array();
                foreach ($content as $url => $content) {
                    if (strlen($content) < self::HEAVY_LOAD_PAGE_SIZE
                        || strpos($content, '</html>') === false
                    ) {
                        // We are going to have to redownload it
                        $unfinishedUrls[$urlsToIds[$url]] = $url;
                        continue;
                    }
                    file_put_contents('list-' . $urlsToIds[$url] . '.html', $content);
                }
                $urls = $unfinishedUrls;
                sleep(1);
            }
        }
        // Parsing list pages to get script ids
        while ($listPageExists) {
            // Getting a list page
            $listPagePath = 'list-' . $pageNum . '.html';
            $listPageExists = file_exists($listPagePath);
            if (!$listPageExists) {
                continue;
            }
            $listPageContent = file_get_contents($listPagePath);
            // Parsing the list page
            $this->log('Parsing list page #' . $pageNum);
            $dom = new DOMDocument();
            if (@$dom->loadHTML($listPageContent) === false) {
                $this->log('Error parsing list page #' . $pageNum);
                continue;
            }
            $scripts = $this->getScripts($dom);
            // Removing ids of scripts we already have
            $foundIds = array();
            foreach (array_keys($scripts) as $id) {
                if (file_exists(self::SAVE_DIR . '/' . $id . '.json')) {
                    $foundIds[] = $id;
                }
            }
            if (!empty($foundIds)) {
                $this->log('Scripts ##' . implode(', ', $foundIds)
                    . ' found locally and are not going to be redownloaded'
                );
                foreach ($foundIds as $id) {
                    unset($scripts[$id]);
                }
            }
            if (empty($scripts)) {
                $this->log('No new scripts on this page, skipping');
                $pageNum++;
                continue;
            }
            // Getting script pages and sources
            $this->log('Getting script pages and sources');
            $urls = array();
            foreach (array_keys($scripts) as $id) {
                $pathFormatScript = 'http://%s:%d' . self::USERSCRIPTS_PATH_SCRIPT_SOURCE;
                $urls['script-' . $id] = sprintf(
                    $pathFormatScript,
                    self::USERSCRIPTS_DOMAIN,
                    self::USERSCRIPTS_PORT,
                    $id
                );
                $pathFormatPage = 'http://%s:%d' . self::USERSCRIPTS_PATH_SCRIPT_PAGE;
                $urls['page-' . $id] = sprintf(
                    $pathFormatPage,
                    self::USERSCRIPTS_DOMAIN,
                    self::USERSCRIPTS_PORT,
                    $id
                );
            }
            $content = $this->curlMultiGet($urls);
            // Parsing
            $this->log('Parsing script pages');
            $descriptions = array();
            $sources = array();
            foreach (array_keys($scripts) as $id) {
                $sources[$id] = $content[$urls['script-' . $id]];
                $scriptPageContent = $content[$urls['page-' . $id]];
                $dom = new DOMDocument();
                if (@$dom->loadHTML($scriptPageContent) === false) {
                    $this->log('Error parsing script page #' . $id);
                    $scripts[$id]->partial = true;
                    $descriptions[$id] = '';
                    continue;
                }
                $scripts[$id] = $this->getScriptInfo($scripts[$id], $dom);
                $descriptions[$id] = $scripts[$id]->description;
                // Description is big and in HTML, lets save it separately
                $scripts[$id]->description = null;
            }
            // Preparing to save files
            if (!file_exists(self::SAVE_DIR) || !is_dir(self::SAVE_DIR)) {
                mkdir(self::SAVE_DIR, 0755, true);
            }
            // Saving everything
            $this->log('Saving everything');
            foreach ($scripts as $id => $meta) {
                file_put_contents(
                    self::SAVE_DIR . '/' . $id . '.json',
                    json_encode($meta)
                );
                file_put_contents(
                    self::SAVE_DIR . '/' . $id . '.js',
                    $sources[$id]
                );
                file_put_contents(
                    self::SAVE_DIR . '/' . $id . '.html',
                    $descriptions[$id]
                );
                file_put_contents(
                    self::SAVE_DIR . '/' . self::LAST_PAGE_FILE,
                    $pageNum
                );
                $this->log('Script #' . $id .  ' saved');
            }
            // All done
            $pageNum++;
        }
    }

    private function getScripts(DOMDocument $dom) {
        $scripts = array();
        foreach ($dom->getElementsByTagName('tr') as $tr) {
            if (!$tr->hasAttribute('id')) {
                continue;
            }
            // Saving script info
            $idParts = explode('-', $tr->getAttribute('id'));
            $id = (integer) $idParts[1];
            $script = new Script();
            $script->id = $id;
            $tds = $tr->getElementsByTagName('td');
            $tdTitle = $tds->item(0);
            $script->title = $tdTitle->getElementsByTagName('a')->item(0)->nodeValue;
            $script->summary = $tdTitle->getElementsByTagName('p')->item(0)->nodeValue;
            $tdReviews = $tds->item(1);
            $spansReviews = $tdReviews->getElementsByTagName('span');
            if ($spansReviews->length > 0) {
                $reviewsStr = $tdReviews->getElementsByTagName('a')->item(0)->nodeValue;
                $reviewsParts = explode(' ', $reviewsStr);
                $script->reviews = (integer) $reviewsParts[0];
                $script->rating = (float) $spansReviews->item(2)->nodeValue;
            }
            $script->posts = (integer) $tds->item(2)->nodeValue;
            $script->fans = (integer) $tds->item(3)->nodeValue;
            $script->installs = (integer) $tds->item(4)->nodeValue;
            $script->lastUpdated = strtotime($tds
                ->item(5)
                ->getElementsByTagName('abbr')
                ->item(0)
                ->getAttribute('title')
            );
            $scripts[$id] = $script;
        }
        return $scripts;
    }

    private function getScriptInfo(Script $script, DOMDocument $dom) {
        $title = $dom->getElementsByTagName('title')->item(0)->nodeValue;
        if (strpos($title, 'DMCA') !== false) {
            $script->error = 'DMCA';
            return $script;
        }
        $tags = array();
        foreach ($dom->getElementsByTagName('ul') as $ul) {
            if ($ul->getAttribute('class') != 'tags') {
                continue;
            }
            foreach ($ul->getElementsByTagName('a') as $a) {
                $tags[] = $a->nodeValue;
            }
        }
        $script->tags = $tags;
        $details = $dom->getElementById('details');
        foreach ($details->getElementsByTagName('a') as $a) {
            if ($a->hasAttribute('user_id')) {
                $script->authorId = (integer) $a->getAttribute('user_id');
                $script->authorName = $a->nodeValue;
                break;
            }
        }
        $descriptionNode = $dom->getElementById('full_description');
        if (!empty($descriptionNode)) {
            $script->description = $descriptionNode->ownerDocument->saveHTML($descriptionNode);
        }
        return $script;
    }

    private function curlMultiGet($urls) {
        $mh = curl_multi_init();
        $handles = array();
        $contents = array();
        foreach ($urls as $url) {
            $handle = curl_init($url);
            curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($handle, CURLOPT_TIMEOUT, 10);
            curl_multi_add_handle($mh, $handle);
            $handles[$url] = $handle;
        }
        $active = null;
        do {
            $mrc = curl_multi_exec($mh, $active);
        } while ($mrc == CURLM_CALL_MULTI_PERFORM);
        while ($active && $mrc == CURLM_OK) {
            $mrc = curl_multi_exec($mh, $active);
            if (curl_multi_select($mh, 10) != -1) {
                do {
                    $mrc = curl_multi_exec($mh, $active);
                } while ($mrc == CURLM_CALL_MULTI_PERFORM);
            } else {
                usleep(10000);
            }
        }
        foreach ($handles as $url => $handle) {
            $contents[$url] = curl_multi_getcontent($handle);
            curl_multi_remove_handle($mh, $handle);
        }
        curl_multi_close($mh);
        foreach ($handles as $handle) {
            curl_close($handle);
        }
        return $contents;
    }

    private function log($message, $writeToFile = true) {
        $line = date('c') . ' - ' . $message . "\n";
        echo $line;
        if ($writeToFile) {
            file_put_contents(self::LOG_FILE, $line, FILE_APPEND);
        }
    }

}

class Script {

    public $id;

    public $title = '';
    public $summary = '';
    public $rating = 0;
    public $reviews = 0;
    public $posts = 0;
    public $fans = 0;
    public $installs = 0;
    public $lastUpdated = '';

    public $tags = array();
    public $authorId = 0;
    public $authorName = '';
    public $description = '';

    public $partial = false;

    public $grabTime = 0;

    public function __construct() {
        $this->grabTime = time();
    }
}

$backup = new Backup();
$backup->go();
