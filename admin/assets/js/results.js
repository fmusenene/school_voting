// results.js

document.addEventListener("DOMContentLoaded", function () {
    fetchVotingResults();
});

function fetchVotingResults() {
    fetch("/api/results/")
        .then((response) => {
            if (!response.ok) {
                throw new Error("Failed to fetch results");
            }
            return response.json();
        })
        .then((data) => {
            renderChart(data);
        })
        .catch((error) => {
            console.error("Error fetching voting results:", error);
        });
}

function renderChart(resultsData) {
    const ctx = document.getElementById("resultsChart").getContext("2d");

    const labels = resultsData.map(item => item.candidate_name);
    const votes = resultsData.map(item => item.vote_count);

    new Chart(ctx, {
        type: "bar",
        data: {
            labels: labels,
            datasets: [{
                label: "Votes",
                data: votes,
                backgroundColor: "rgba(54, 162, 235, 0.6)",
                borderColor: "rgba(54, 162, 235, 1)",
                borderWidth: 1
            }]
        },
        options: {
            scales: {
                y: {
                    beginAtZero: true,
                    precision: 0
                }
            },
            plugins: {
                title: {
                    display: true,
                    text: "E-Voting Results"
                }
            }
        }
    });
}
