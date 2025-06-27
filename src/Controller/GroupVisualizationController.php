<?php

namespace App\Controller;

use App\Entity\Usergroup;
use App\Security\UserVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class GroupVisualizationController extends AbstractController
{
    /**
     * @Route("/visualization/groups", name="groups_visualization")
     */
    public function index(): Response
    {
        $this->denyAccessUnlessGranted(UserVoter::LOGGED);
        
        return $this->render('pages/group/groups-visualization.html.twig', [
            'title' => 'Visualisation des interconnexions entre groupes'
        ]);
    }

    /**
     * @Route("/api/groups/graph-data", name="groups_graph_data", methods={"GET"})
     */
    public function getGraphData(EntityManagerInterface $manager): JsonResponse
    {
        try {
            $this->denyAccessUnlessGranted(UserVoter::LOGGED);
            
            // Test simple d'abord
            $groups = $manager->getRepository(Usergroup::class)->findAll();
            
            $nodes = [];
            $links = [];
            $nodeMap = [];

            // Créer les nœuds (version simplifiée pour debug)
            foreach ($groups as $group) {
                if (!$group->getIsActive()) {
                    continue; // Skip inactive groups
                }
                
                $nodeId = 'group_' . $group->getId();
                $nodeMap[$group->getId()] = $nodeId;

                $nodes[] = [
                    'id' => $nodeId,
                    'name' => $group->getName(),
                    'slug' => $group->getSlug(),
                    'description' => $group->getDescription() ?: '',
                    'isImportant' => $group->getIsImportant() ?? false,
                    'memberCount' => count($group->getMembers()),
                    'visibility' => $group->getVisibility(),
                    'url' => $this->generateUrl('group_index', ['groupSlug' => $group->getSlug()])
                ];
            }

            // Créer les liens parent-enfant (version simplifiée)
            foreach ($groups as $group) {
                if (!$group->getIsActive() || !isset($nodeMap[$group->getId()])) {
                    continue;
                }
                
                $sourceId = $nodeMap[$group->getId()];

                // Liens vers les enfants
                if (method_exists($group, 'getChildren')) {
                    foreach ($group->getChildren() as $child) {
                        if (isset($nodeMap[$child->getId()])) {
                            $targetId = $nodeMap[$child->getId()];
                            $links[] = [
                                'source' => $sourceId,
                                'target' => $targetId,
                                'type' => 'parent-child'
                            ];
                        }
                    }
                }
            }

            return new JsonResponse([
                'nodes' => $nodes,
                'links' => $links,
                'debug' => [
                    'total_groups' => count($groups),
                    'active_groups' => count($nodes)
                ]
            ]);
            
        } catch (\Exception $e) {
            return new JsonResponse([
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ], 500);
        }
    }
}