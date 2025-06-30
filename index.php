<?php
session_start();

if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true){
    header("location: login.php");
    exit;
}

require_once "config.php";
require_once "upload_handler.php";

// Traitement de la suppression d'un tweet
if($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["delete_tweet_id"])){
    $tweet_id = $_POST["delete_tweet_id"];
    $user_id = $_SESSION["id"];
    
    // Vérifier que le tweet appartient à l'utilisateur connecté
    $check_sql = "SELECT media_path FROM tweets WHERE id = ? AND user_id = ?";
    if($check_stmt = $mysqli->prepare($check_sql)){
        $check_stmt->bind_param("ii", $tweet_id, $user_id);
        $check_stmt->execute();
        $result = $check_stmt->get_result();
        
        if($result->num_rows > 0){
            $tweet_data = $result->fetch_assoc();
            
            // Supprimer le fichier média s'il existe
            if(!empty($tweet_data['media_path']) && file_exists($tweet_data['media_path'])){
                unlink($tweet_data['media_path']);
            }
            
            // Supprimer le tweet de la base de données
            $delete_sql = "DELETE FROM tweets WHERE id = ? AND user_id = ?";
            if($delete_stmt = $mysqli->prepare($delete_sql)){
                $delete_stmt->bind_param("ii", $tweet_id, $user_id);
                $delete_stmt->execute();
                $delete_stmt->close();
            }
        }
        $check_stmt->close();
    }
    
    header("location: index.php");
    exit;
}

// Traitement de l'ajout d'un nouveau tweet avec média
if($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["tweet_content"])){
    $tweet_content = trim($_POST["tweet_content"]);
    $media_path = null;
    $media_type = null;
    $upload_error = null;
    
    if(!empty($tweet_content) && strlen($tweet_content) <= 280){
        
        // Traitement de l'upload de fichier
        if(isset($_FILES["media_file"]) && $_FILES["media_file"]["error"] == 0){
            $upload_result = validateAndUploadMedia($_FILES["media_file"]);
            
            if($upload_result['success']){
                $media_path = $upload_result['path'];
                $media_type = $upload_result['type'];
            } else {
                $upload_error = $upload_result['error'];
            }
        }
        
        // Insérer le tweet seulement si pas d'erreur d'upload
        if($upload_error === null){
            $sql = "INSERT INTO tweets (user_id, content, media_path, media_type) VALUES (?, ?, ?, ?)";
            
            if($stmt = $mysqli->prepare($sql)){
                $stmt->bind_param("isss", $_SESSION["id"], $tweet_content, $media_path, $media_type);
                
                if($stmt->execute()){
                    header("location: index.php");
                    exit;
                } else{
                    $upload_error = "Erreur lors de l'ajout du tweet.";
                    // Supprimer le fichier uploadé en cas d'erreur de base de données
                    if($media_path) deleteMediaFile($media_path);
                }
                $stmt->close();
            }
        }
    } else {
        $upload_error = "Le contenu du tweet ne peut pas être vide ou dépasser 280 caractères.";
    }
}

// Traitement des likes/unlikes
if($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["like_tweet_id"])){
    $tweet_id = $_POST["like_tweet_id"];
    $user_id = $_SESSION["id"];
    
    // Vérifier si l'utilisateur a déjà liké ce tweet
    $check_sql = "SELECT id FROM likes WHERE user_id = ? AND tweet_id = ?";
    if($check_stmt = $mysqli->prepare($check_sql)){
        $check_stmt->bind_param("ii", $user_id, $tweet_id);
        $check_stmt->execute();
        $check_stmt->store_result();
        
        if($check_stmt->num_rows > 0){
            // Unlike - supprimer le like
            $unlike_sql = "DELETE FROM likes WHERE user_id = ? AND tweet_id = ?";
            if($unlike_stmt = $mysqli->prepare($unlike_sql)){
                $unlike_stmt->bind_param("ii", $user_id, $tweet_id);
                $unlike_stmt->execute();
                $unlike_stmt->close();
            }
        } else {
            // Like - ajouter le like
            $like_sql = "INSERT INTO likes (user_id, tweet_id) VALUES (?, ?)";
            if($like_stmt = $mysqli->prepare($like_sql)){
                $like_stmt->bind_param("ii", $user_id, $tweet_id);
                $like_stmt->execute();
                $like_stmt->close();
            }
        }
        $check_stmt->close();
    }
    
    header("location: index.php");
    exit;
}

// Traitement de la recherche
$search_query = "";
$search_results_message = "";
if(isset($_GET["search"]) && !empty(trim($_GET["search"]))){
    $search_query = trim($_GET["search"]);
    $search_results_message = "Résultats pour : \"" . htmlspecialchars($search_query) . "\"";
}

// Récupérer les tweets avec ou sans recherche
if(!empty($search_query)){
    // Recherche dans les tweets
    $sql = "SELECT t.id, t.content, t.media_path, t.media_type, t.created_at, t.user_id, u.username, 
            (SELECT COUNT(*) FROM likes l WHERE l.tweet_id = t.id) as like_count,
            (SELECT COUNT(*) FROM likes l WHERE l.tweet_id = t.id AND l.user_id = ?) as user_liked
            FROM tweets t 
            JOIN users u ON t.user_id = u.id 
            WHERE t.content LIKE ? OR u.username LIKE ?
            ORDER BY t.created_at DESC";
    
    $tweets = [];
    if($stmt = $mysqli->prepare($sql)){
        $search_param = "%" . $search_query . "%";
        $stmt->bind_param("iss", $_SESSION["id"], $search_param, $search_param);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while($row = $result->fetch_assoc()){
            $tweets[] = $row;
        }
        $stmt->close();
    }
    
    if(empty($tweets)){
        $search_results_message = "Aucun résultat trouvé pour : \"" . htmlspecialchars($search_query) . "\"";
    }
} else {
    // Récupérer tous les tweets
    $sql = "SELECT t.id, t.content, t.media_path, t.media_type, t.created_at, t.user_id, u.username, 
            (SELECT COUNT(*) FROM likes l WHERE l.tweet_id = t.id) as like_count,
            (SELECT COUNT(*) FROM likes l WHERE l.tweet_id = t.id AND l.user_id = ?) as user_liked
            FROM tweets t 
            JOIN users u ON t.user_id = u.id 
            ORDER BY t.created_at DESC";

    $tweets = [];
    if($stmt = $mysqli->prepare($sql)){
        $stmt->bind_param("i", $_SESSION["id"]);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while($row = $result->fetch_assoc()){
            $tweets[] = $row;
        }
        $stmt->close();
    }
}

$mysqli->close();
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TweetFX - Accueil</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand" href="index.php">
                <i class="fab fa-twitter"></i> TweetFX
            </a>
            
            <!-- Barre de recherche au centre -->
            <div class="mx-auto">
                <form class="d-flex" method="GET" action="index.php">
                    <div class="input-group">
                        <input type="text" name="search" class="form-control search-input" 
                               placeholder="Rechercher des tweets..." 
                               value="<?php echo htmlspecialchars($search_query); ?>"
                               style="width: 300px;">
                        <button class="btn btn-outline-light" type="submit">
                            <i class="fas fa-search"></i>
                        </button>
                        <?php if(!empty($search_query)): ?>
                            <a href="index.php" class="btn btn-outline-light" title="Effacer la recherche">
                                <i class="fas fa-times"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
            
            <div class="navbar-nav">
                <span class="navbar-text me-3">Bienvenue, <?php echo htmlspecialchars($_SESSION["username"]); ?>!</span>
                <a href="logout.php" class="btn btn-outline-light btn-sm">Déconnexion</a>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <!-- Message de résultats de recherche -->
                <?php if(!empty($search_results_message)): ?>
                    <div class="alert alert-info d-flex justify-content-between align-items-center">
                        <span><?php echo $search_results_message; ?></span>
                        <?php if(!empty($search_query)): ?>
                            <a href="index.php" class="btn btn-sm btn-outline-primary">
                                <i class="fas fa-arrow-left"></i> Voir tous les tweets
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <!-- Formulaire pour nouveau tweet (masqué lors de la recherche) -->
                <?php if(empty($search_query)): ?>
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">Quoi de neuf ?</h5>
                    </div>
                    <div class="card-body">
                        <?php if(isset($upload_error) && !empty($upload_error)): ?>
                            <div class="alert alert-danger">
                                <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($upload_error); ?>
                            </div>
                        <?php endif; ?>
                        
                        <form method="post" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" enctype="multipart/form-data">
                            <div class="mb-3">
                                <textarea name="tweet_content" class="form-control" rows="3" placeholder="Exprimez-vous en 280 caractères maximum..." maxlength="280" required></textarea>
                                <div class="form-text">
                                    <span id="char-count">0</span>/280 caractères
                                </div>
                            </div>
                            
                            <!-- Upload de média -->
                            <div class="mb-3">
                                <label for="media_file" class="form-label">
                                    <i class="fas fa-image"></i> Ajouter une image ou vidéo (optionnel)
                                </label>
                                <input type="file" name="media_file" id="media_file" class="form-control" 
                                       accept="image/*,video/*" onchange="previewMedia(this)">
                                <div class="form-text">
                                    Formats supportés : JPG, PNG, GIF, WebP, MP4, AVI, MOV, WebM (max 10MB)
                                </div>
                                
                                <!-- Prévisualisation du média -->
                                <div id="media_preview" class="mt-2" style="display: none;">
                                    <div class="position-relative d-inline-block">
                                        <img id="preview_image" src="" alt="Prévisualisation" class="img-thumbnail" style="max-width: 200px; display: none;">
                                        <video id="preview_video" controls class="img-thumbnail" style="max-width: 200px; display: none;"></video>
                                        <button type="button" class="btn btn-sm btn-danger position-absolute top-0 end-0" onclick="removeMediaPreview()">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-paper-plane"></i> Tweeter
                            </button>
                        </form>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Affichage des tweets -->
                <?php if(empty($tweets)): ?>
                    <div class="card">
                        <div class="card-body text-center">
                            <?php if(!empty($search_query)): ?>
                                <p class="text-muted">
                                    <i class="fas fa-search fa-2x mb-3"></i><br>
                                    Aucun tweet trouvé pour votre recherche.
                                </p>
                                <a href="index.php" class="btn btn-primary">Voir tous les tweets</a>
                            <?php else: ?>
                                <p class="text-muted">Aucun tweet pour le moment. Soyez le premier à tweeter !</p>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php else: ?>
                    <?php foreach($tweets as $tweet): ?>
                        <div class="card mb-3">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <h6 class="card-title mb-0">
                                        <i class="fas fa-user-circle"></i> 
                                        <?php echo htmlspecialchars($tweet['username']); ?>
                                    </h6>
                                    <div class="d-flex align-items-center">
                                        <small class="text-muted me-2">
                                            <?php echo date('d/m/Y H:i', strtotime($tweet['created_at'])); ?>
                                        </small>
                                        
                                        <!-- Bouton de suppression (seulement pour ses propres tweets) -->
                                        <?php if($tweet['user_id'] == $_SESSION["id"]): ?>
                                            <form method="post" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" class="d-inline" onsubmit="return confirm('Êtes-vous sûr de vouloir supprimer ce tweet ?');">
                                                <input type="hidden" name="delete_tweet_id" value="<?php echo $tweet['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Supprimer ce tweet">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <p class="card-text">
                                    <?php 
                                    // Surligner les mots recherchés
                                    $content = htmlspecialchars($tweet['content']);
                                    if(!empty($search_query)){
                                        $content = preg_replace('/(' . preg_quote($search_query, '/') . ')/i', '<mark>$1</mark>', $content);
                                    }
                                    echo $content;
                                    ?>
                                </p>
                                
                                <!-- Affichage du média -->
                                <?php if(!empty($tweet['media_path']) && file_exists($tweet['media_path'])): ?>
                                    <div class="media-container mb-3">
                                        <?php if($tweet['media_type'] == 'image'): ?>
                                            <img src="<?php echo htmlspecialchars($tweet['media_path']); ?>" 
                                                 alt="Image du tweet" 
                                                 class="img-fluid rounded tweet-media" 
                                                 style="max-height: 400px; cursor: pointer;"
                                                 onclick="openMediaModal('<?php echo htmlspecialchars($tweet['media_path']); ?>', 'image')">
                                        <?php elseif($tweet['media_type'] == 'video'): ?>
                                            <video controls class="img-fluid rounded tweet-media" style="max-height: 400px;">
                                                <source src="<?php echo htmlspecialchars($tweet['media_path']); ?>" type="video/mp4">
                                                Votre navigateur ne supporte pas la lecture vidéo.
                                            </video>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                                
                                <div class="d-flex align-items-center">
                                    <form method="post" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" class="d-inline">
                                        <input type="hidden" name="like_tweet_id" value="<?php echo $tweet['id']; ?>">
                                        <?php if(!empty($search_query)): ?>
                                            <input type="hidden" name="search_return" value="<?php echo htmlspecialchars($search_query); ?>">
                                        <?php endif; ?>
                                        <button type="submit" class="btn btn-link p-0 text-decoration-none <?php echo $tweet['user_liked'] > 0 ? 'text-danger' : 'text-muted'; ?>">
                                            <i class="<?php echo $tweet['user_liked'] > 0 ? 'fas' : 'far'; ?> fa-heart"></i>
                                            <?php echo $tweet['like_count']; ?>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Modal pour afficher les images en grand -->
    <div class="modal fade" id="mediaModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Média</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center">
                    <img id="modalImage" src="" alt="Image" class="img-fluid" style="display: none;">
                    <video id="modalVideo" controls class="img-fluid" style="display: none;">
                        <source src="" type="video/mp4">
                    </video>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="script.js"></script>
</body>
</html>

