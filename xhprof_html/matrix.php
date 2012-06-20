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

$raw_data_threshold = array();
foreach ($raw_data[0] as $parent_child=>$info) {
    if ($info['wt'] > $threshold) {
        $raw_data_threshold[$parent_child] = $info;
    }
}
$flat_data = xhprof_compute_flat_info($raw_data_threshold, $overall_totals);
unset($raw_data_threshold['main()']);

$symbols = array_flip(array_keys($flat_data));
$links = array();
$maxMetrics = array('wt' => 0, 'ct' => 0, 'mu' => 0, 'pmu' => 0);

foreach ($raw_data_threshold as $parent_child=>$info) {
    list($parent, $child) = xhprof_parse_parent_child($parent_child);
    $links[] = array(
        'source' => $symbols[$parent],
        'target' => $symbols[$child],
        'info' => $info
    );
    if ($maxMetrics['wt'] < $info['wt']) {
        $maxMetrics['wt'] = $info['wt'];
    }
    if ($maxMetrics['ct'] < $info['ct']) {
        $maxMetrics['ct'] = $info['ct'];
    }
    if ($maxMetrics['mu'] < $info['mu']) {
        $maxMetrics['mu'] = $info['mu'];
    }
    if ($maxMetrics['pmu'] < $info['pmu']) {
        $maxMetrics['pmu'] = $info['pmu'];
    }
}

$symbols = array_flip($symbols);
foreach ($symbols as $k=>$symbol) {
    $symbols[$k] = $flat_data[$symbol]+array('name'=>$symbol);
}

?>
<style>
svg {
    font: 8px sans-serif;
}
.background {
    fill: #FFFFFF;
}
line {
    stroke: #EEE;
}
text.active {
    fill: #0066FF;
    font-weight: bold;
}
.cell_wt {
    fill: #0066FF;
}
.cell_ct {
    fill: red;
}
.cell_mu {
    fill: green;
}
.cell_pmu {
    fill: yellow;
}
.cell_sep {
    fill: #EEE;
}
</style>

Show : 
<select id="show">
    <option value="wt" selected="selected">walltime</option>
    <option value="ct">count</option>
    <option value="cpu">cpu</option>
    <option value="mu">memory usage</option>
    <option value="pmu">peak memory usage</option>
</select>

Sort by : 
<select id="order">
<?php foreach (array_keys($symbols[0]) as $k):?>
<option value="<?php echo $k?>"><?php echo $k?></option>
<?php endforeach;?>
</select>

<script type="text/javascript">
var symbols = <?php echo json_encode($symbols);?>;
var links = <?php echo json_encode($links);?>;
var maxMetrics = <?php echo json_encode($maxMetrics);?>;
var nodeKeys = d3.keys(links[0].info);
var margin = {top: 120, right: 0, bottom: 10, left: 120},
    width = 1200, 
    height = width;

var matrix = [];
var nbSymbols = symbols.length;
    
var svg = d3.select("body").append("svg")
    .attr("width", width + margin.left + margin.right)
    .attr("height", height + margin.top + margin.bottom)
  .append("g")
    .attr("transform", "translate(" + margin.left + "," + margin.top + ")");
    
var orders = {
    name: d3.range(nbSymbols).sort(function(a, b) { return d3.ascending(symbols[a].name, symbols[b].name); }),
};
var z = {};
d3.keys(symbols[0]).forEach(function(key, i) {
	if (key != 'name') {
	    orders[key] = d3.range(nbSymbols).sort(function(a, b) { return symbols[b][key] - symbols[a][key]; });
	    z[key] = d3.scale.linear().domain([0, maxMetrics[key]]).clamp(true);
	}
});

var x = d3.scale.ordinal().rangeBands([0, width]);
x.domain(orders.name);

svg.append("rect")
    .attr("class", "background")
    .attr("width", width)
    .attr("height", height);



symbols.forEach(function(symbol, i) {
    matrix[i] = d3.range(nbSymbols).map(function(j) {
        var info = {};
    	nodeKeys.forEach(function (k) {info[k]=0;});
        return {x: j, y: i, info: info}; 
    });
});

links.forEach(function(link) {
    matrix[link.source][link.target].info = link.info;
    matrix[link.target][link.source].info = link.info;
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
    .text(function(d, i) { return symbols[i].name; });

    
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
    .text(function(d, i) { return symbols[i].name; });

function row(row) {
	var g = this;
	
	d3.keys(row[0].info).forEach(function(k) {
		var cell = d3.select(g).selectAll(".cell_"+k)
            .data(row.filter(function(d) { return d.info[k] || d.x==d.y; }))
            .enter().append("rect")
            .attr("class", function(d) {if(d.x==d.y) { return "cell cell_sep";} return "cell cell_"+k;} )
            .attr("x", function(d, i) { return x(d.x); })
            .attr("width", x.rangeBand())
            .attr("height", x.rangeBand())
            .style("fill-opacity", function(d) { if(d.x==d.y) { return 1;} return z[k](d.info[k]); })
            .on("mouseover", mouseover)
            .on("mouseout", mouseout)
            .on("click", function (p) {
                alert(symbols[p.y].name+'/'+symbols[p.x].name+'='+p.info[k]);
            });
	});
}

function mouseover(p) 
{
    d3.selectAll(".row text").classed("active", function(d, i) { return i == p.y; });
    d3.selectAll(".column text").classed("active", function(d, i) { return i == p.x; });
}

function mouseout() 
{
    d3.selectAll("text").classed("active", false);
}

function show(value)
{
	nodeKeys.forEach(function (k) {
		if (k == value) {
			var display = 'inline';
		} else {
			var display = 'none';
		}
		svg.selectAll('.cell_'+k)
            .attr('display', display);
	});
}

function order(value) 
{
    x.domain(orders[value]);
    
    svg.selectAll(".row")
        .attr("transform", function(d, i) { return "translate(0," + x(i) + ")"; })
      .selectAll(".cell")
        .attr("x", function(d) { return x(d.x); });
    
    svg.selectAll(".column")
        .attr("transform", function(d, i) { return "translate(" + x(i) + ")rotate(-90)"; });
}

d3.select("#order").on("change", function() {
    order(this.value);
});
d3.select('#show').on("change", function() {
    show(this.value);
});
show('wt');
order('wt');
</script>