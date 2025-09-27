<?php
session_start();
if (!isset($_SESSION['studentId'])) {
    header('Location: index.php');
    exit;
}

require_once 'conexion.php';

$studentId = $_SESSION['studentId'];

// Fetch all videos for the dropdown
$stmt = $mysqli->prepare("SELECT id, title FROM videos ORDER BY title");
$stmt->execute();
$videosResult = $stmt->get_result();
$videos = $videosResult->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get selected videoId from GET or default to first video
$selectedVideoId = $_GET['videoId'] ?? ($videos[0]['id'] ?? '');

$mysqli->close();
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Video - Detección de Emociones</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/@tensorflow/tfjs@latest/dist/tf.min.js"></script>
    <script src="https://www.youtube.com/iframe_api"></script>
    <style>
        #webcam {
            border: 1px solid #dee2e6;
            border-radius: 0.25rem;
            max-width: 100%;
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand" href="#">Detección de Emociones</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="index.php">Iniciar Sesión</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="register.php">Registrarse</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="video.php">Video</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="dashboard.php">Tablero</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="logout.php">Cerrar Sesión</a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-lg-10">
                <div class="card shadow">
                    <div class="card-body">
                        <h2 class="card-title text-center">Ver Video</h2>
                        <div class="mb-4">
                            <form method="GET" class="row g-3 justify-content-center">
                                <div class="col-md-6">
                                    <label for="videoId" class="form-label">Seleccionar Video</label>
                                    <select id="videoId" name="videoId" class="form-select" onchange="this.form.submit()">
                                        <?php foreach ($videos as $video): ?>
                                            <option value="<?php echo htmlspecialchars($video['id']); ?>" 
                                                <?php echo $video['id'] === $selectedVideoId ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($video['title']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                        <?php if (empty($videos)): ?>
                                            <option value="">No hay videos disponibles</option>
                                        <?php endif; ?>
                                    </select>
                                </div>
                            </form>
                        </div>
                        <div class="alert alert-info text-center">
                            Aviso de privacidad: El procesamiento de emociones se realiza localmente en tu navegador. No se almacenan imágenes ni videos de la cámara web. Solo se envían resultados agregados con tu consentimiento.
                        </div>
                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <h5 class="text-center">Video</h5>
                                <div class="ratio ratio-16x9">
                                    <div id="player"></div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <h5 class="text-center">Cámara en Tiempo Real</h5>
                                <video id="webcam" width="320" height="240" autoplay playsinline></video>
                            </div>
                        </div>
                        <canvas id="canvas" class="d-none"></canvas>
                        <div id="emotion-display" class="alert alert-secondary text-center" role="alert">
                            Emoción Detectada: Ninguna
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        const EMOTIONS = ['angry', 'disgust', 'fear', 'happy', 'sad', 'surprise', 'neutral'];
        const EMOJI_MAP = {
            'angry': '😣',
            'disgust': '🤢',
            'fear': '😨',
            'happy': '😊',
            'sad': '😢',
            'surprise': '😮',
            'neutral': '😐'
        };
        let model;
        let player;
        let videoElement;
        let canvas;
        const studentId = '<?php echo $studentId; ?>';
        const videoId = '<?php echo $selectedVideoId; ?>';
        let intervalId;
        const API_URL = 'https://emotions.serviciosempresariales.cloud/emotions.php';

        async function loadModel() {
            try {
                model = await tf.loadGraphModel('model.json');
                console.log('Modelo cargado');
            } catch (error) {
                console.error('Error al cargar el modelo:', error);
                alert('No se pudo cargar el modelo de detección de emociones.');
            }
        }

        async function initWebcam() {
            videoElement = document.getElementById('webcam');
            canvas = document.getElementById('canvas');
            try {
                const stream = await navigator.mediaDevices.getUserMedia({ video: true });
                videoElement.srcObject = stream;
            } catch (err) {
                alert('Error al acceder a la cámara web: ' + err.message);
            }
        }

        function onYouTubeIframeAPIReady() {
            if (!videoId) return;
            player = new YT.Player('player', {
                height: '100%',
                width: '100%',
                videoId: videoId,
                events: {
                    'onReady': onPlayerReady,
                    'onStateChange': onPlayerStateChange
                }
            });
        }

        function onPlayerReady(event) {
            event.target.playVideo();
        }

        function onPlayerStateChange(event) {
            if (event.data === YT.PlayerState.PLAYING) {
                if (!intervalId) {
                    intervalId = setInterval(detectEmotion, 15000);
                }
            } else if (event.data === YT.PlayerState.PAUSED || event.data === YT.PlayerState.ENDED) {
                if (intervalId) {
                    clearInterval(intervalId);
                    intervalId = null;
                }
            }
        }

        async function detectEmotion() {
            if (!model || !videoElement.videoWidth || !videoId) return;

            canvas.width = 48;
            canvas.height = 48;
            const ctx = canvas.getContext('2d');
            ctx.drawImage(videoElement, 0, 0, 48, 48);

            let img = tf.browser.fromPixels(canvas, 1);
            img = tf.image.resizeBilinear(img, [48, 48]);
            img = img.expandDims(0).toFloat().div(tf.scalar(255));

            const predictions = await model.predict(img).data();
            const maxIndex = predictions.indexOf(Math.max(...predictions));
            const dominantEmotion = EMOTIONS[maxIndex];
            const emotionProbabilities = {};
            EMOTIONS.forEach((emotion, i) => {
                emotionProbabilities[emotion] = predictions[i];
            });

            document.getElementById('emotion-display').innerText = `Emoción Detectada: ${dominantEmotion} ${EMOJI_MAP[dominantEmotion]}`;

            const timestamp = new Date().toISOString();

            const payload = {
                studentId: studentId,
                videoId: videoId,
                timestamp: timestamp,
                dominantEmotion: dominantEmotion,
                emotionProbabilities: emotionProbabilities
            };

            try {
                const response = await fetch(API_URL, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await response.json();
                console.log('Respuesta API:', data);
                if (!data.success) {
                    console.error('Error API:', data.error);
                }
            } catch (error) {
                console.error('Error de Fetch:', error);
            }

            tf.dispose(img);
        }

        initWebcam();
        loadModel();
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>