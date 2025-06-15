<?php
/*
 SPDX-FileCopyrightText: © Fossology contributors
 SPDX-License-Identifier: GPL-2.0-only
*/

namespace Fossology\Reuser;

use Fossology\Lib\Auth\Auth;
use Fossology\Lib\Dao\FolderDao;
use Fossology\Lib\Plugin\DefaultPlugin;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Fossology\Lib\Util\OsselotLookupHelper;

include_once(__DIR__ . "/../agent/version.php");

/**
 * @class ReuserPlugin
 * @brief UI plugin for reuser
 */
class ReuserPlugin extends DefaultPlugin
{
  const NAME = "plugin_reuser";             ///< UI mod name

  const REUSE_FOLDER_SELECTOR_NAME = 'reuseFolderSelectorName'; ///< Reuse upload folder element name
  const UPLOAD_TO_REUSE_SELECTOR_NAME = 'uploadToReuse';  ///< Upload to reuse HTML element name
  const FOLDER_PARAMETER_NAME = 'folder';   ///< Folder parameter HTML element name

  /** @var string $AgentName
   * Agent name from DB
   */
  public $AgentName = 'agent_reuser';
  /** @var FolderDao $folderDao
   * Folder Dao object
   */
  private $folderDao;

  public function __construct()
  {
    parent::__construct(self::NAME, array(
        self::TITLE => _("Automatic Clearing Decision Reuser"),
        self::PERMISSION => Auth::PERM_WRITE
    ));

    $this->folderDao = $this->getObject('dao.folder');
  }

    /**
     * Handle AJAX calls for local uploads & OSSelot version lookup
     */
    protected function handle(Request $request)
    {
        $this->folderDao->ensureTopLevelFolder();
        $ajax = $request->get('do');

        if ($ajax === 'getUploads') {
            list($fid, $tgid) = $this->getFolderIdAndTrustGroup($request->get(self::FOLDER_PARAMETER_NAME, ''));
            $uploads = (empty($fid) || empty($tgid))
                ? $this->getAllUploads()
                : $this->prepareFolderUploads($fid, $tgid);
            return new JsonResponse($uploads, JsonResponse::HTTP_OK);
        }

        if ($ajax === 'getOsselotVersions') {
          $pkg = trim($request->get('pkg', $request->get('osselotPackage', '')));
            if ($pkg === '') {
                return new JsonResponse([], JsonResponse::HTTP_OK);
            }
            $helper = new OsselotLookupHelper();
            try {
                $versions = $helper->getVersions($pkg);
            } catch (\Exception $e) {
                $versions = [];
            }
            return new JsonResponse($versions, JsonResponse::HTTP_OK);
        }

        return new Response('called without valid method', Response::HTTP_METHOD_NOT_ALLOWED);
    }

    /**
     * Render the reuse UI, including OSSelot section
     */
    public function renderContent(&$vars)
    {
        if (!isset($vars['folderStructure'])) {
            $rootId = $this->folderDao->getRootFolder(Auth::getUserId())->getId();
            $vars['folderStructure'] = $this->folderDao->getFolderStructure($rootId);
        }
        if ($this->folderDao->isWithoutReusableFolders($vars['folderStructure'])) {
            return '';
        }

        $pair = $vars[self::FOLDER_PARAMETER_NAME] ?? '';
        list($fid, $tgid) = $this->getFolderIdAndTrustGroup($pair);
        if (empty($fid) && !empty($vars['folderStructure'])) {
            $fid = $vars['folderStructure'][0][FolderDao::FOLDER_KEY]->getId();
        }

        $vars['reuseFolderSelectorName']    = self::REUSE_FOLDER_SELECTOR_NAME;
        $vars['folderParameterName']        = self::FOLDER_PARAMETER_NAME;
        $vars['uploadToReuseSelectorName']  = self::UPLOAD_TO_REUSE_SELECTOR_NAME;
        $vars['folderUploads']              = $this->prepareFolderUploads($fid, $tgid);

        // OSSelot feature toggle
        $vars['osselotAvailable'] = true;
        // default package name (e.g. from upload filename base)
        $vars['defaultPkgName']  = $vars['uploadFilename'] ?? 'angular';
        // whether current user is admin (for showing 'New license' option)
        $vars['userIsAdmin']     = false;

        $twig = $this->getObject('twig.environment');
        return $twig->load('agent_reuser.html.twig')->render($vars);
    }

    /**
     * Render JS behaviors
     */
    public function renderFoot(&$vars)
    {
        $vars['reuseFolderSelectorName']    = self::REUSE_FOLDER_SELECTOR_NAME;
        $vars['folderParameterName']        = self::FOLDER_PARAMETER_NAME;
        $vars['uploadToReuseSelectorName']  = self::UPLOAD_TO_REUSE_SELECTOR_NAME;
        $vars['osselotAvailable']           = true;

        $twig = $this->getObject('twig.environment');
        return $twig->load('agent_reuser.js.twig')->render($vars);
    }

    /** Retrieve all uploads for current user */
    private function getAllUploads(): array
    {
        $folders = $this->folderDao->getAllFolderIds();
        $out = [];
        foreach ($folders as $fid) {
            foreach ($this->prepareFolderUploads($fid) as $k => $v) {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    /** Build uploads list for folder/trust-group */
    private function prepareFolderUploads($folderId, $trustGroupId = null): array
    {
        if ($trustGroupId === null) {
            $trustGroupId = Auth::getGroupId();
        }
        $entries = $this->folderDao->getFolderUploads($folderId, $trustGroupId);
        $out = [];
        foreach ($entries as $up) {
            $key = $up->getId() . ',' . $up->getGroupId();
            $out[$key] = sprintf(
                "%s from %s (%s)",
                $up->getFilename(),
                Convert2BrowserTime(date('Y-m-d H:i:s', $up->getTimestamp())),
                $up->getStatusString()
            );
        }
        return $out;
    }

    /** Split 'folder,trust' into integers */
    private function getFolderIdAndTrustGroup(string $pair): array
    {
        $parts = explode(',', $pair, 2);
        if (count($parts) === 2) {
            return [(int)$parts[0], (int)$parts[1]];
        }
        return [0, Auth::getGroupId()];
    }
}

register_plugin(new ReuserPlugin());
