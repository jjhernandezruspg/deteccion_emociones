<?php
session_start();

require_once 'conexion.php';

// Fetch all students and videos for dropdowns
$stmt = $mysqli->prepare("SELECT id, name FROM students ORDER BY id");
$stmt->execute();
$students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$stmt = $mysqli->prepare("SELECT id, title FROM videos ORDER BY title");
$stmt->execute();
$videos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get filters from GET or default to first student/video
$studentIdFilter = $_GET['studentId'] ?? ($students[0]['id'] ?? '');
$videoIdFilter = $_GET['videoId'] ?? ($videos[0]['id'] ?? '');

// Fetch emotions data for the selected student and video
$whereClause = [];
$params = [];
$types = '';
if ($studentIdFilter) {
    $whereClause[] = 'studentId = ?';
    $params[] = $studentIdFilter;
    $types .= 's';
}
if ($videoIdFilter) {
    $whereClause[] = 'videoId = ?';
    $params[] = $videoIdFilter;
    $types .= 's';
}
$query = "SELECT * FROM emotions" . ($whereClause ? " WHERE " . implode(' AND ', $whereClause) : "") . " ORDER BY timestamp";
$stmt = $mysqli->prepare($query);
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Process data for time series and stacked bar
$labels = [];
$happy = [];
$angry = [];
$disgust = [];
$fear = [];
$sad = [];
$surprise = [];
$neutral = [];

foreach ($data as $row) {
    $labels[] = date('H:i:s', strtotime($row['timestamp']));
    $happy[] = $row['prob_happy'];
    $angry[] = $row['prob_angry'];
    $disgust[] = $row['prob_disgust'];
    $fear[] = $row['prob_fear'];
    $sad[] = $row['prob_sad'];
    $surprise[] = $row['prob_surprise'];
    $neutral[] = $row['prob_neutral'];
}

// Process data for heatmap (aggregate by minute)
$heatmapData = [];
foreach ($data as $row) {
    $minute = date('Y-m-d H:i:00', strtotime($row['timestamp']));
    if (!isset($heatmapData[$minute])) {
        $heatmapData[$minute] = [
            'angry' => 0, 'disgust' => 0, 'fear' => 0, 'happy' => 0,
            'sad' => 0, 'surprise' => 0, 'neutral' => 0, 'count' => 0
        ];
    }
    $heatmapData[$minute]['angry'] += $row['prob_angry'];
    $heatmapData[$minute]['disgust'] += $row['prob_disgust'];
    $heatmapData[$minute]['fear'] += $row['prob_fear'];
    $heatmapData[$minute]['happy'] += $row['prob_happy'];
    $heatmapData[$minute]['sad'] += $row['prob_sad'];
    $heatmapData[$minute]['surprise'] += $row['prob_surprise'];
    $heatmapData[$minute]['neutral'] += $row['prob_neutral'];
    $heatmapData[$minute]['count'] += 1;
}
$heatmapLabels = array_keys($heatmapData);
$heatmapDatasets = [];
$emotions = ['happy', 'angry', 'disgust', 'fear', 'sad', 'surprise', 'neutral'];
foreach ($emotions as $emotion) {
    $heatmapDatasets[$emotion] = array_map(function($minute) use ($heatmapData, $emotion) {
        return $heatmapData[$minute]['count'] ? $heatmapData[$minute][$emotion] / $heatmapData[$minute]['count'] : 0;
    }, $heatmapLabels);
}

// Process data for student comparison (average probabilities per student)
$comparisonData = [];
$stmt = $mysqli->prepare("SELECT studentId, AVG(prob_happy) as happy, AVG(prob_angry) as angry, 
    AVG(prob_disgust) as disgust, AVG(prob_fear) as fear, AVG(prob_sad) as sad, 
    AVG(prob_surprise) as surprise, AVG(prob_neutral) as neutral 
    FROM emotions" . ($videoIdFilter ? " WHERE videoId = ?" : "") . " GROUP BY studentId");
if ($videoIdFilter) {
    $stmt->bind_param('s', $videoIdFilter);
}
$stmt->execute();
$comparisonData = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$comparisonLabels = array_column($comparisonData, 'studentId');
$comparisonDatasets = [];
foreach ($emotions as $emotion) {
    $comparisonDatasets[$emotion] = array_map(function($row) use ($emotion) {
        return $row[$emotion];
    }, $comparisonData);
}

$mysqli->close();
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tablero - Detección de Emociones</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .chart-container {
            position: relative;
            min-height: 400px;
            width: 100%;
        }
        .emotion-toggle {
            margin-bottom: 1rem;
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
                        <a class="nav-link" href="video.php">Video</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="dashboard.php">Tablero</a>
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
                        <h2 class="card-title text-center">Tablero de Emociones</h2>
                        <div class="mb-4">
                            <form method="GET" class="row g-3 justify-content-center">
                                <div class="col-md-4">
                                    <label for="studentId" class="form-label">Seleccionar Estudiante</label>
                                    <select id="studentId" name="studentId" class="form-select" onchange="this.form.submit()">
                                        <option value="">Todos los Estudiantes</option>
                                        <?php foreach ($students as $student): ?>
                                            <option value="<?php echo htmlspecialchars($student['id']); ?>" 
                                                <?php echo $student['id'] === $studentIdFilter ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($student['id'] . ' - ' . $student['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label for="videoId" class="form-label">Seleccionar Video</label>
                                    <select id="videoId" name="videoId" class="form-select" onchange="this.form.submit()">
                                        <option value="">Todos los Videos</option>
                                        <?php foreach ($videos as $video): ?>
                                            <option value="<?php echo htmlspecialchars($video['id']); ?>" 
                                                <?php echo $video['id'] === $videoIdFilter ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($video['title']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </form>
                        </div>
                        <div class="mb-3 text-center">
                            <button id="export-csv" class="btn btn-success">Exportar a CSV</button>
                        </div>
                        <ul class="nav nav-tabs mb-4" id="chartTabs" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active" id="time-series-tab" data-bs-toggle="tab" data-bs-target="#time-series" type="button" role="tab">Serie Temporal</button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="stacked-bar-tab" data-bs-toggle="tab" data-bs-target="#stacked-bar" type="button" role="tab">Barras Apiladas</button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="heatmap-tab" data-bs-toggle="tab" data-bs-target="#heatmap" type="button" role="tab">Mapa de Calor</button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="comparison-tab" data-bs-toggle="tab" data-bs-target="#comparison" type="button" role="tab">Comparación de Estudiantes</button>
                            </li>
                        </ul>
                        <div class="tab-content">
                            <div class="tab-pane fade show active" id="time-series" role="tabpanel">
                                <?php if (empty($data)): ?>
                                    <div class="alert alert-warning text-center">No hay datos disponibles para el estudiante y video seleccionados.</div>
                                <?php else: ?>
                                    <div class="emotion-toggle text-center">
                                        <div class="btn-group" role="group" aria-label="Alternar emociones">
                                            <button class="btn btn-outline-primary btn-sm toggle-emotion" data-emotion="happy">Felicidad 😊</button>
                                            <button class="btn btn-outline-primary btn-sm toggle-emotion" data-emotion="angry">Enojo 😣</button>
                                            <button class="btn btn-outline-primary btn-sm toggle-emotion" data-emotion="disgust">Asco 🤢</button>
                                            <button class="btn btn-outline-primary btn-sm toggle-emotion" data-emotion="fear">Miedo 😨</button>
                                            <button class="btn btn-outline-primary btn-sm toggle-emotion" data-emotion="sad">Tristeza 😢</button>
                                            <button class="btn btn-outline-primary btn-sm toggle-emotion" data-emotion="surprise">Sorpresa 😮</button>
                                            <button class="btn btn-outline-primary btn-sm toggle-emotion" data-emotion="neutral">Neutral 😐</button>
                                        </div>
                                    </div>
                                    <div class="chart-container">
                                        <canvas id="time-series-chart"></canvas>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="tab-pane fade" id="stacked-bar" role="tabpanel">
                                <?php if (empty($data)): ?>
                                    <div class="alert alert-warning text-center">No hay datos disponibles para el estudiante y video seleccionados.</div>
                                <?php else: ?>
                                    <div class="chart-container">
                                        <canvas id="stacked-bar-chart"></canvas>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="tab-pane fade" id="heatmap" role="tabpanel">
                                <?php if (empty($heatmapData)): ?>
                                    <div class="alert alert-warning text-center">No hay datos disponibles para el mapa de calor.</div>
                                <?php else: ?>
                                    <div class="chart-container">
                                        <canvas id="heatmap-chart"></canvas>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="tab-pane fade" id="comparison" role="tabpanel">
                                <?php if (count($comparisonData) < 2): ?>
                                    <div class="alert alert-warning text-center">Se requieren datos de al menos dos estudiantes para la comparación.</div>
                                <?php else: ?>
                                    <div class="chart-container">
                                        <canvas id="comparison-chart"></canvas>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Time Series Chart
        <?php if (!empty($data)): ?>
        const timeSeriesCtx = document.getElementById('time-series-chart').getContext('2d');
        const timeSeriesChart = new Chart(timeSeriesCtx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($labels); ?>,
                datasets: [
                    { label: 'Felicidad 😊', data: <?php echo json_encode($happy); ?>, borderColor: 'green', fill: false, hidden: false },
                    { label: 'Enojo 😣', data: <?php echo json_encode($angry); ?>, borderColor: 'red', fill: false, hidden: false },
                    { label: 'Asco 🤢', data: <?php echo json_encode($disgust); ?>, borderColor: 'purple', fill: false, hidden: false },
                    { label: 'Miedo 😨', data: <?php echo json_encode($fear); ?>, borderColor: 'orange', fill: false, hidden: false },
                    { label: 'Tristeza 😢', data: <?php echo json_encode($sad); ?>, borderColor: 'blue', fill: false, hidden: false },
                    { label: 'Sorpresa 😮', data: <?php echo json_encode($surprise); ?>, borderColor: 'yellow', fill: false, hidden: false },
                    { label: 'Neutral 😐', data: <?php echo json_encode($neutral); ?>, borderColor: 'gray', fill: false, hidden: false }
                ]
            },
            options: {
                scales: {
                    y: { beginAtZero: true, max: 1, title: { display: true, text: 'Probabilidad de Emoción' } },
                    x: { title: { display: true, text: 'Tiempo (HH:MM:SS)' } }
                },
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: true, position: 'top' },
                    tooltip: { enabled: true, mode: 'index', intersect: false }
                }
            }
        });

        // Toggle emotion visibility for time series
        document.querySelectorAll('.toggle-emotion').forEach(button => {
            button.addEventListener('click', () => {
                const emotion = button.getAttribute('data-emotion');
                const dataset = timeSeriesChart.data.datasets.find(ds => ds.label.toLowerCase().startsWith(emotion));
                dataset.hidden = !dataset.hidden;
                button.classList.toggle('btn-primary');
                button.classList.toggle('btn-outline-primary');
                timeSeriesChart.update();
            });
        });
        <?php endif; ?>

        // Stacked Bar Chart
        <?php if (!empty($data)): ?>
        const stackedBarCtx = document.getElementById('stacked-bar-chart').getContext('2d');
        new Chart(stackedBarCtx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($labels); ?>,
                datasets: [
                    { label: 'Felicidad 😊', data: <?php echo json_encode($happy); ?>, backgroundColor: 'green' },
                    { label: 'Enojo 😣', data: <?php echo json_encode($angry); ?>, backgroundColor: 'red' },
                    { label: 'Asco 🤢', data: <?php echo json_encode($disgust); ?>, backgroundColor: 'purple' },
                    { label: 'Miedo 😨', data: <?php echo json_encode($fear); ?>, backgroundColor: 'orange' },
                    { label: 'Tristeza 😢', data: <?php echo json_encode($sad); ?>, backgroundColor: 'blue' },
                    { label: 'Sorpresa 😮', data: <?php echo json_encode($surprise); ?>, backgroundColor: 'yellow' },
                    { label: 'Neutral 😐', data: <?php echo json_encode($neutral); ?>, backgroundColor: 'gray' }
                ]
            },
            options: {
                scales: {
                    y: { stacked: true, beginAtZero: true, max: 1, title: { display: true, text: 'Probabilidad de Emoción' } },
                    x: { stacked: true, title: { display: true, text: 'Tiempo (HH:MM:SS)' } }
                },
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: true, position: 'top' },
                    tooltip: { enabled: true, mode: 'index', intersect: false }
                }
            }
        });
        <?php endif; ?>

        // Heatmap Chart (Stacked Bar by Minute)
        <?php if (!empty($heatmapData)): ?>
        const heatmapCtx = document.getElementById('heatmap-chart').getContext('2d');
        new Chart(heatmapCtx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode(array_map(function($minute) { return date('H:i', strtotime($minute)); }, $heatmapLabels)); ?>,
                datasets: [
                    { label: 'Felicidad 😊', data: <?php echo json_encode($heatmapDatasets['happy']); ?>, backgroundColor: 'green' },
                    { label: 'Enojo 😣', data: <?php echo json_encode($heatmapDatasets['angry']); ?>, backgroundColor: 'red' },
                    { label: 'Asco 🤢', data: <?php echo json_encode($heatmapDatasets['disgust']); ?>, backgroundColor: 'purple' },
                    { label: 'Miedo 😨', data: <?php echo json_encode($heatmapDatasets['fear']); ?>, backgroundColor: 'orange' },
                    { label: 'Tristeza 😢', data: <?php echo json_encode($heatmapDatasets['sad']); ?>, backgroundColor: 'blue' },
                    { label: 'Sorpresa 😮', data: <?php echo json_encode($heatmapDatasets['surprise']); ?>, backgroundColor: 'yellow' },
                    { label: 'Neutral 😐', data: <?php echo json_encode($heatmapDatasets['neutral']); ?>, backgroundColor: 'gray' }
                ]
            },
            options: {
                scales: {
                    y: { stacked: true, beginAtZero: true, max: 1, title: { display: true, text: 'Probabilidad Promedio de Emoción' } },
                    x: { title: { display: true, text: 'Tiempo (HH:MM)' } }
                },
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: true, position: 'top' },
                    tooltip: {
                        enabled: true,
                        mode: 'index',
                        intersect: false,
                        callbacks: {
                            label: function(context) {
                                const emotion = context.dataset.label;
                                const value = context.parsed.y.toFixed(2);
                                const time = context.label;
                                return `${emotion} a las ${time}: ${value}`;
                            }
                        }
                    }
                }
            }
        });
        <?php endif; ?>

        // Student Comparison Chart
        <?php if (count($comparisonData) >= 2): ?>
        const comparisonCtx = document.getElementById('comparison-chart').getContext('2d');
        new Chart(comparisonCtx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($comparisonLabels); ?>,
                datasets: [
                    { label: 'Felicidad 😊', data: <?php echo json_encode($comparisonDatasets['happy']); ?>, backgroundColor: 'green' },
                    { label: 'Enojo 😣', data: <?php echo json_encode($comparisonDatasets['angry']); ?>, backgroundColor: 'red' },
                    { label: 'Asco 🤢', data: <?php echo json_encode($comparisonDatasets['disgust']); ?>, backgroundColor: 'purple' },
                    { label: 'Miedo 😨', data: <?php echo json_encode($comparisonDatasets['fear']); ?>, backgroundColor: 'orange' },
                    { label: 'Tristeza 😢', data: <?php echo json_encode($comparisonDatasets['sad']); ?>, backgroundColor: 'blue' },
                    { label: 'Sorpresa 😮', data: <?php echo json_encode($comparisonDatasets['surprise']); ?>, backgroundColor: 'yellow' },
                    { label: 'Neutral 😐', data: <?php echo json_encode($comparisonDatasets['neutral']); ?>, backgroundColor: 'gray' }
                ]
            },
            options: {
                scales: {
                    y: { beginAtZero: true, max: 1, title: { display: true, text: 'Probabilidad Promedio de Emoción' } },
                    x: { title: { display: true, text: 'Carnet' } }
                },
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: true, position: 'top' },
                    tooltip: { enabled: true, mode: 'index', intersect: false }
                }
            }
        });
        <?php endif; ?>

        // CSV Export
        document.getElementById('export-csv').addEventListener('click', () => {
            const headers = ['studentId', 'videoId', 'timestamp', 'dominantEmotion', 'prob_happy', 'prob_angry', 'prob_disgust', 'prob_fear', 'prob_sad', 'prob_surprise', 'prob_neutral'];
            const csv = [
                headers.join(','),
                ...<?php echo json_encode($data); ?>.map(row => [
                    row.studentId,
                    row.videoId,
                    `"${row.timestamp}"`,
                    row.dominantEmotion,
                    row.prob_happy,
                    row.prob_angry,
                    row.prob_disgust,
                    row.prob_fear,
                    row.prob_sad,
                    row.prob_surprise,
                    row.prob_neutral
                ].join(','))
            ].join('\n');
            const blob = new Blob([csv], { type: 'text/csv' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'datos_emociones.csv';
            a.click();
            URL.revokeObjectURL(url);
        });
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>