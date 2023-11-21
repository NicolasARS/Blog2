<?php

namespace App\Controller;

use App\Entity\Category;
use Symfony\Component\Filesystem\Filesystem;
use App\Entity\Comment;
use App\Entity\Post;
use App\Entity\Like;
use App\Form\CommentFormType;
use App\Form\PostFormType;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\Security;
use Symfony\Component\String\Slugger\SluggerInterface;


class BlogController extends AbstractController
{
    #[Route("/blog/buscar/{page}", name: 'blog_buscar')]
    public function buscar(ManagerRegistry $doctrine,  Request $request, int $page = 1): Response
    {
    $repository = $doctrine->getRepository(Post::class);
    $searchTerm = $request->query->get('searchTerm', '');
    $posts = $repository->findByTitle($searchTerm);
    $recents = $repository->findRecents();
    $repositoryCategories = $doctrine->getRepository(Category::class);
    $categories = $repositoryCategories->findAll();
    return $this->render('blog/blog.html.twig', [
        'posts' => $posts,
        'recents' => $recents,
        'categories' => $categories,
        'searchTerm' => $searchTerm
    ]);
    }
   
    #[Route("/blog/new", name: 'new_post')]
    public function newPost(ManagerRegistry $doctrine, Request $request, SluggerInterface $slugger): Response
    {
        $post = new Post();
        $form = $this->createForm(PostFormType::class, $post);

        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            // Procesar la carga de la imagen
            $file = $form->get('Image')->getData();
            $user = $this->getUser();
            if ($file) {
                $originalFilename = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
                $safeFilename = $slugger->slug($originalFilename);
                $newFilename = $safeFilename.'-'.uniqid().'.'.$file->guessExtension();
                $post->setImage($newFilename);
                $uploadDir = $this->getParameter('kernel.project_dir') . '/public/images/blog';
                $file->move($uploadDir, $newFilename);
            }

            // Guardar la entidad en la base de datos
            $form = $form->getData();
            $slug = $slugger->slug($post->getTitle());
            $post->setSlug($slug);

            $currentTime = new \DateTime('now');
            $post->setPublishedAt($currentTime);
            
            $post->setUser($user);
            $post->setNumLikes(0);
            $post->setNumComments(0);
            $post->setNumViews(0);
            $entityManager = $doctrine->getManager();
            $entityManager->persist($post);
            $entityManager->flush();

            // Redirigir o mostrar una confirmación
            return $this->redirectToRoute('blog', ["slug" => $post->getSlug()]);
        }

        return $this->render('blog/new_post.html.twig', [
            'form' => $form->createView(),
        ]);
    }
    
    #[Route("/single_post/{slug}/like", name: 'post_like')]
    public function like(ManagerRegistry $doctrine, $slug, Security $security): Response
    {
    $repository = $doctrine->getRepository(Post::class);
    $post = $repository->findOneBy(['Slug' => $slug]);

    // Verificar si la publicación existe
    if (!$post) {
        throw $this->createNotFoundException('El post solicitado no existe.');
    }

    $user = $security->getUser();

    // Verificar si el usuario ya ha dado "like"
    $likeRepo = $doctrine->getRepository(Like::class);
    $like = $likeRepo->findOneBy(['post' => $post, 'user' => $user]);

    if (!$like) {
        // Si no ha dado "like", proceder a agregarlo
        $like = new Like();
        $like->setPost($post)->setUser($user);
        $currentTime = new \DateTime('now');
        $like->setPublishedAt($currentTime);
        $post->addLike()->addView();

        $entityManager = $doctrine->getManager();
        $entityManager->persist($like);
        $entityManager->persist($post);
        $entityManager->flush();
    }

    return $this->redirectToRoute('single_post', ["slug" => $post->getSlug()]);
}

    #[Route("/blog", name: 'blog')]
    public function index(ManagerRegistry $doctrine): Response
    {
        $repositoryPosts = $doctrine->getRepository(Post::class);
        $posts = $repositoryPosts->findAll();
        $repositoryCategories = $doctrine->getRepository(Category::class);
        $categories = $repositoryCategories->findAll();
        
        return $this->render('blog/blog.html.twig', [
            'posts' => $posts,
            'categories' => $categories
        ]);
    }

    #[Route("/single_post/{slug}", name: 'single_post')]
    public function post(ManagerRegistry $doctrine, Request $request, $slug = 'cambiar', ): Response
    {
        $repository = $doctrine->getRepository(Post::class);
        $post = $repository->findOneBy(['Slug' => $slug]);

        if (!$post){
            throw $this->createNotFoundException('El post solicitado no existe.');
        }

        $comment = new Comment();
        $commentRepo = $doctrine->getRepository(Comment::class);
        $comments = $commentRepo->findBy(['post' => $post]);
        $recentsRepo = $doctrine->getRepository(Post::class);
        $recents = $recentsRepo->findAll();
        $form = $this->createForm(CommentFormType::class, $comment);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()){
            $comment->setPost($post);
            
            $entityManager = $doctrine->getManager();
            $entityManager->persist($comment);
            $entityManager->flush();
        }
        return $this->render('blog/single_post.html.twig', [
            'post' => $post,
            'comments' => $comments,
            'recents' => $recents,
            'commentForm' => $form->createView()
        ]);
    }

}
