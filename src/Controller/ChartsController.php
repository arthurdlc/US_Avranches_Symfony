<?php

namespace App\Controller;

use App\Entity\Tests;
use App\Entity\Height;
use App\Entity\Weight;
use App\Entity\ChartConfiguration;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use App\Repository\ChartConfigurationRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

class ChartsController extends AbstractController
{
    #[Route('/charts', name: 'app_charts_index', methods: ['GET'])]
    public function index(Request $request, ChartConfigurationRepository $configRepository, EntityManagerInterface $entityManager, UserRepository $userRepository): Response
    {
        // Récupérer l'utilisateur connecté
        $user = $this->getUser();
        
        // Rediriger si l'utilisateur n'est pas authentifié
        if (!$user) {
            return $this->redirectToRoute('app_verif_code', [], Response::HTTP_SEE_OTHER);
        }
        
        // Initialiser la variable pour stocker les données des graphiques
        $chartData = [];

        // Vérifier le rôle de l'utilisateur
        if ($this->isGranted('ROLE_COACH')) {
            // Si l'utilisateur est un coach, récupérer les joueurs à partir du formulaire
            $selectedUserId = $request->query->get('userId');
            $selectedUser = $userRepository->find($selectedUserId);

            // Si un joueur est sélectionné, afficher ses graphiques
            if ($selectedUser) {
                $users = [$selectedUser];
            } else {
                // Sinon, afficher les graphiques de tous les joueurs
                $users = $userRepository->findByRole('ROLE_PLAYER');
            }
        } else {
            // Si l'utilisateur est un joueur, utiliser uniquement ses propres données de graphique
            $users = [$user];
        }

        // Récupérer toutes les configurations de graphique
        $configurations = $configRepository->findAll();

        // Parcourir les configurations de graphique
        foreach ($configurations as $config) {
            // Récupérer les données spécifiques en fonction de l'entité configurée
            $entity = $config->getConfigData()['entity'];
            foreach ($users as $currentUser) {
                $data = [];
                if ($entity === 'App\Entity\Height') {
                    $data = $entityManager->getRepository(Height::class)->findBy(['user' => $currentUser]);
                } elseif ($entity === 'App\Entity\Weight') {
                    $data = $entityManager->getRepository(Weight::class)->findBy(['user' => $currentUser]);
                } elseif ($entity === 'App\Entity\Tests') {
                    $data = $entityManager->getRepository(Tests::class)->findBy(['user' => $currentUser]);
                }

                // Générer les données du graphique
                $chartData[$currentUser->getId()][$config->getId()] = [
                    'name' => $config->getName(),
                    'chartType' => $config->getChartType(),
                    'data' => $this->generateLineChartData($data, $config->getConfigData()['field']),
                    'min' => $config->getConfigData()['min'],
                    'max' => $config->getConfigData()['max'],
                ];
            }
        }

        return $this->render('charts/index.html.twig', [
            'chartData' => $chartData,
            'users' => $users,
            'selectedUserId' => $selectedUserId ?? null,
            'location' => 'b',
        ]);
    }

    private function generateLineChartData($data, $field)
    {
        $labels = [];
        $values = [];
    
        // Parcourir les données pour générer les données du graphique en ligne
        foreach ($data as $item) {
            $labels[] = $item->getDate()->format('d-m-Y');
            // Utiliser la méthode get avec le nom du champ spécifié dans la configuration
            $values[] = $item->{'get' . ucfirst($field)}();
        }

        return [
            'labels' => $labels,
            'values' => $values,
        ];
    }

    #[Route('/charts/update', name: 'app_charts_update', methods: ['GET'])]
    public function updateScale(Request $request, EntityManagerInterface $entityManager): Response
    {
        $min = $request->request->get('min');
        $max = $request->request->get('max');

        $chartId = $request->request->get('chart_id');

        $chartConfiguration = $entityManager->getRepository(ChartConfiguration::class)->find($chartId);

        if (!$chartConfiguration) {
            return new Response('Chart configuration not found', Response::HTTP_NOT_FOUND);
        }

        // Mettre à jour les valeurs de l'échelle dans la configuration de graphique
        $configData = $chartConfiguration->getConfigData();
        $configData['min'] = $min;
        $configData['max'] = $max;
        $chartConfiguration->setConfigData($configData);

        // Enregistrer les changement
        $entityManager->flush();

        return new Response('Chart scale updated successfully', Response::HTTP_OK);
    }
}
