<?php
require_once ("../xhprof_lib/config.php");
require_once ("../xhprof_lib/templates/header.phtml");

if (false !== $controlIPs && !in_array($_SERVER['REMOTE_ADDR'], $controlIPs))
{
  die("You do not have permission to view this page.");
}

// by default assume that xhprof_html & xhprof_lib directories
// are at the same level.
if (!defined('XHPROF_LIB_ROOT')) {
  define('XHPROF_LIB_ROOT', dirname(dirname(__FILE__)) . '/xhprof_lib');
}

include_once XHPROF_LIB_ROOT . '/display/xhprof.php';

ini_set('max_execution_time', 100);

$params = array(// run id param
        'run' => array(XHPROF_STRING_PARAM, ''),
        // source/namespace/type of run
        'source' => array(XHPROF_STRING_PARAM, 'xhprof'),
        // only functions whose exclusive time over the total time
        // is larger than this threshold will be shown.
        // default is 0.01.
        'threshold' => array(XHPROF_FLOAT_PARAM, 0.01),
);

// pull values of these params, and create named globals for each param
xhprof_param_init($params);
$totals;

$xhprof_runs_impl = new XHProfRuns_Default();
$raw_data = $xhprof_runs_impl->get_run($run, $totals, $desc_unused);
$flat_data = xhprof_compute_flat_info($raw_data[0], $overall_totals);
unset($raw_data[0]['main()']);

$symbols = array_flip(array_keys($flat_data));
$links = array();

foreach ($raw_data[0] as $parent_child=>$info) {
    list($parent, $child) = xhprof_parse_parent_child($parent_child);
    $links[] = array(
        'source' => $symbols[$parent],
        'target' => $symbols[$child],
        'info' => $info
    );
}
?>
<style>
svg {
    font: 10px sans-serif;
}
.background {
    fill: #EEEEEE;
}
line {
    stroke: #FFFFFF;
}
</style>
<script type="text/javascript">
var symbols = <?php echo json_encode(array_keys($symbols));?>;
var links = <?php echo json_encode($links);?>;
var margin = {top: 80, right: 0, bottom: 10, left: 80},
    width = 1200, 
    height = width;

var svg = d3.select("body").append("svg")
    .attr("width", width + margin.left + margin.right)
    .attr("height", height + margin.top + margin.bottom)
  .append("g")
    .attr("transform", "translate(" + margin.left + "," + margin.top + ")");
    
var z = d3.scale.linear().domain([0, 100]).clamp(true);
var x = d3.scale.ordinal().rangeBands([0, width]);
x.domain(symbols);

svg.append("rect")
    .attr("class", "background")
    .attr("width", width)
    .attr("height", height);

var matrix = [];
var nbSymbols = symbols.length;

symbols.forEach(function(symbol, i) {
    matrix[i] = d3.range(nbSymbols).map(function(j) { return {x: j, y: i, z: 0}; });
});

links.forEach(function(link) {
    matrix[link.source][link.target].z += link.info.wt;
    matrix[link.target][link.source].z += link.info.wt;
    matrix[link.source][link.source].z += link.info.wt;
    matrix[link.target][link.target].z += link.info.wt;
});

var row = svg.selectAll(".row")
    .data(matrix)
    .enter().append("g")
    .attr("class", "row")
    .attr("transform", function(d, i) { return "translate(0," + x(i) + ")"; })
    .each(row);
    
row.append("line")
    .attr("x2", width);
    
row.append("text")
    .attr("x", -120)
    .attr("y", x.rangeBand() / 2)
    .attr("dy", ".32em")
    .attr("text-anchor", "start")
    .text(function(d, i) { return symbols[i]; });

    
var column = svg.selectAll(".column")
    .data(matrix)
    .enter().append("g")
    .attr("class", "column")
    .attr("transform", function(d, i) { return "translate(" + x(i) + ")rotate(-90)"; });

column.append("line")
    .attr("x1", -width);
    
column.append("text")
    .attr("x", 6)
    .attr("y", x.rangeBand() / 2)
    .attr("dy", ".32em")
    .attr("text-anchor", "start")
    .text(function(d, i) { return symbols[i]; });

function row(row) {
    var cell = d3.select(this).selectAll(".cell")
        .data(row.filter(function(d) { return d.z; }))
        .enter().append("rect")
        .attr("class", "cell")
        .attr("x", function(d, i) { return x(d.x); })
        .attr("width", x.rangeBand())
        .attr("height", x.rangeBand())
        .style("fill-opacity", function(d) { return z(d.z); });
}
</script>